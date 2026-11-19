<?php
declare(strict_types=1);

namespace App\Repo;

use App\Domain\DocType;
use App\Domain\DocumentLocked;
use App\Domain\DocumentNotFound;

/**
 * Documents, their lines and payments of the context company (tables documents,
 * document_lines, payments; all access through the BaseRepo scoped helpers).
 *
 * Reads return normalised rows: integer columns as int (money in minor units, signed as stored:
 * credit notes negative), dates as 'Y-m-d' strings, `seller`/`buyer` decoded from the JSON
 * snapshots (null for drafts), and, from find(), `lines` ordered by position.
 *
 * The writers are meant for DocumentService only. Every writer that touches an existing
 * document re-reads it and refuses anything that is not a draft (DocumentLocked), so no code
 * path here changes or deletes an issued document or its lines. Payments can only be inserted.
 */
final class DocumentRepo extends BaseRepo
{
    private const DOCS = 'documents';
    private const LINES = 'document_lines';
    private const PAYMENTS = 'payments';
    public const STATUSES = ['draft', 'issued'];

    private const DOC_INTS = ['id', 'company_id', 'reverse_charge', 'net_minor', 'vat_minor', 'gross_minor'];
    private const DOC_NULLABLE_INTS = ['related_document_id', 'customer_id', 'vat_eur_minor', 'created_by'];
    private const LINE_INTS = ['id', 'company_id', 'document_id', 'position', 'qty_milli', 'unit_minor', 'discount_bp', 'vat_bp', 'net_minor'];

    /** @return array<string, mixed>|null the document with `lines`, `seller`, `buyer` */
    public function find(int $id): ?array
    {
        $row = $this->scopedOne(self::DOCS, $id);
        if ($row === null) {
            return null;
        }
        $doc = $this->normalize($row);
        $doc['lines'] = $this->lines($id);
        return $doc;
    }

    /**
     * Documents ordered by issue_date DESC, id DESC (drafts without a date come last).
     * Filters: type, status (unknown values match nothing), customer_id (int), q (number or
     * buyer name, substring, case-insensitive). Unknown filter keys are ignored.
     *
     * buyer_name is a snapshot too: issued documents show the name from the issue, drafts the
     * customer's name as of the last draft save (a later customer rename is not reflected until
     * the draft is saved again).
     *
     * Search design (same as CustomerRepo): user input goes only into `?` parameters; % _ and
     * backslash are stripped from the term (BaseRepo forbids backslashes, so no ESCAPE clause).
     *
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>> rows without lines
     */
    public function list(array $filter = [], ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = $this->filterWhere($filter);
        return array_map(
            fn (array $r): array => $this->normalize($r),
            $this->scopedAll(self::DOCS, $where, $params, 'issue_date DESC, id DESC', $limit, $offset)
        );
    }

    /** @param array<string, mixed> $filter same as list() */
    public function count(array $filter = []): int
    {
        [$where, $params] = $this->filterWhere($filter);
        return $this->scopedCount(self::DOCS, $where, $params);
    }

    /**
     * The document row (no lines), plain (non-locking) read.
     *
     * @return array<string, mixed>|null
     */
    public function row(int $id): ?array
    {
        $row = $this->scopedOne(self::DOCS, $id);
        return $row === null ? null : $this->normalize($row);
    }

    /**
     * The document row (no lines) read with a locking read; use inside a transaction.
     *
     * @return array<string, mixed>|null
     */
    public function lockedFind(int $id): ?array
    {
        $row = $this->scopedAll(self::DOCS, 'id = ?', [$id], '', null, 0, true)[0] ?? null;
        return $row === null ? null : $this->normalize($row);
    }

    /** @return list<array<string, mixed>> lines of a document, by position */
    public function lines(int $documentId): array
    {
        return array_map(
            fn (array $r): array => $this->ints($r, self::LINE_INTS, ['item_id']),
            $this->scopedAll(self::LINES, 'document_id = ?', [$documentId], 'position ASC, id ASC')
        );
    }

    /** Is (type, number) already used by a document of this company? Locking read. */
    public function numberTaken(string $type, string $number): bool
    {
        return $this->scopedAll(self::DOCS, 'type = ? AND number = ?', [$type, $number], '', 1, 0, true) !== [];
    }

    /**
     * Issued credit notes of an invoice (locking read when $lock).
     *
     * @return list<array<string, mixed>>
     */
    public function issuedCreditNotes(int $invoiceId, bool $lock = false): array
    {
        return array_map(fn (array $r): array => $this->normalize($r), $this->scopedAll(
            self::DOCS,
            "type = 'credit_note' AND status = 'issued' AND related_document_id = ?",
            [$invoiceId],
            'id ASC',
            null,
            0,
            $lock
        ));
    }

    /** @return list<array<string, mixed>> payments of a document, oldest first */
    public function payments(int $documentId, bool $lock = false): array
    {
        return array_map(
            fn (array $r): array => $this->ints($r, ['id', 'company_id', 'document_id', 'amount_minor'], ['created_by']),
            $this->scopedAll(self::PAYMENTS, 'document_id = ?', [$documentId], 'paid_on ASC, id ASC', null, 0, $lock)
        );
    }

    /** Does the item belong to the context company? */
    public function itemExists(int $itemId): bool
    {
        return $this->scopedCount('items', 'id = ?', [$itemId]) === 1;
    }

    /**
     * The current companies row of the context company (seller data). `companies` has no
     * company_id: the row is selected by the context id only, never by a caller's id.
     *
     * @return array<string, mixed>
     */
    public function company(): array
    {
        return $this->db->one('SELECT * FROM companies WHERE id = ?', [$this->ctx->id()]) ?? [];
    }

    // ---- writers (DocumentService only) ----

    /** @param array<string, mixed> $row */
    public function insertDraft(array $row): int
    {
        $row['status'] = 'draft';
        $row['number'] = null;
        return $this->scopedInsert(self::DOCS, $row);
    }

    /**
     * Updates a DRAFT row (also the update that turns it into an issued document).
     *
     * @param array<string, mixed> $row
     * @throws DocumentNotFound|DocumentLocked
     */
    public function updateDraftRow(int $id, array $row): void
    {
        $this->assertDraft($id);
        $this->scopedUpdate(self::DOCS, $id, $row);
    }

    /**
     * Replaces all lines of a DRAFT with $lines (positions 1..n in the given order).
     *
     * @param list<array<string, mixed>> $lines
     * @throws DocumentNotFound|DocumentLocked
     */
    public function replaceLines(int $documentId, array $lines): void
    {
        $this->assertDraft($documentId);
        foreach ($this->scopedAll(self::LINES, 'document_id = ?', [$documentId]) as $old) {
            $this->scopedDelete(self::LINES, (int) $old['id']);
        }
        $pos = 0;
        foreach ($lines as $line) {
            $this->scopedInsert(self::LINES, ['document_id' => $documentId, 'position' => ++$pos] + $line);
        }
    }

    /**
     * Deletes a DRAFT and its lines (call inside a transaction).
     *
     * @throws DocumentNotFound|DocumentLocked
     */
    public function deleteDraft(int $id): void
    {
        $this->replaceLines($id, []);
        $this->scopedDelete(self::DOCS, $id);
    }

    /** @param array<string, mixed> $row */
    public function insertPayment(array $row): int
    {
        return $this->scopedInsert(self::PAYMENTS, $row);
    }

    // ---- internals ----

    private function assertDraft(int $id): void
    {
        $row = $this->scopedOne(self::DOCS, $id) ?? throw new DocumentNotFound();
        if ($row['status'] !== 'draft') {
            throw new DocumentLocked();
        }
    }

    /**
     * @param array<string, mixed> $filter
     * @return array{0: string, 1: list<scalar>}
     */
    private function filterWhere(array $filter): array
    {
        $conds = [];
        $params = [];
        if (isset($filter['type'])) {
            if (!is_string($filter['type']) || !DocType::isValid($filter['type'])) {
                return ['1=0', []];
            }
            $conds[] = 'type = ?';
            $params[] = $filter['type'];
        }
        if (isset($filter['status'])) {
            if (!in_array($filter['status'], self::STATUSES, true)) {
                return ['1=0', []];
            }
            $conds[] = 'status = ?';
            $params[] = $filter['status'];
        }
        if (isset($filter['customer_id'])) {
            $conds[] = 'customer_id = ?';
            $params[] = (int) $filter['customer_id'];
        }
        $q = is_scalar($filter['q'] ?? null) ? trim(str_replace(['%', '_', '\\'], '', (string) $filter['q'])) : '';
        if ($q !== '') {
            $lower = mb_strtolower($q, 'UTF-8');
            $variants = array_values(array_unique([
                $q, $lower, mb_strtoupper($q, 'UTF-8'), mb_convert_case($q, MB_CASE_TITLE, 'UTF-8'),
            ]));
            $or = ['LOWER(number) LIKE ?', 'LOWER(buyer_name) LIKE ?'];
            $params[] = '%' . $lower . '%';
            $params[] = '%' . $lower . '%';
            foreach ($variants as $v) {
                $or[] = 'buyer_name LIKE ?';
                $params[] = '%' . $v . '%';
            }
            $conds[] = '(' . implode(' OR ', $or) . ')';
        }
        return [$conds === [] ? '1=1' : implode(' AND ', $conds), $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $row = $this->ints($row, self::DOC_INTS, self::DOC_NULLABLE_INTS);
        foreach (['seller' => 'seller_json', 'buyer' => 'buyer_json'] as $key => $col) {
            $row[$key] = is_string($row[$col] ?? null) ? json_decode($row[$col], true, 16, JSON_THROW_ON_ERROR) : null;
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $ints
     * @param list<string> $nullable
     * @return array<string, mixed>
     */
    private function ints(array $row, array $ints, array $nullable): array
    {
        foreach ($ints as $c) {
            $row[$c] = (int) $row[$c];
        }
        foreach ($nullable as $c) {
            $row[$c] = $row[$c] === null ? null : (int) $row[$c];
        }
        return $row;
    }
}
