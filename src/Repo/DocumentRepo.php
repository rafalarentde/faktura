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
    /** Stored offer statuses (DocumentService::OFFER_STATUSES; 'expired' is computed, never stored). */
    private const OFFER_STATUSES = ['open', 'sent', 'accepted', 'rejected'];
    /** Payment states of issued invoices (paymentRows; same values as DocumentService::paymentSummary). */
    public const PAYMENT_STATUSES = ['open', 'partial', 'overdue', 'paid', 'credited'];

    private const DOC_INTS = ['id', 'company_id', 'reverse_charge', 'net_minor', 'vat_minor', 'gross_minor'];
    private const DOC_NULLABLE_INTS = ['related_document_id', 'customer_id', 'vat_eur_minor', 'created_by', 'dunning_level',
        'dunning_outstanding_minor', 'dunning_interest_minor', 'dunning_interest_days', 'dunning_interest_rate_bp', 'dunning_flat_fee_minor',
        'dunning_fee_minor', 'dunning_claim_minor'];
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
     * Filters: type, types (non-empty list of types: any of them), status (unknown values match
     * nothing; an empty or invalid types list matches nothing too), customer_id (int),
     * related_document_id (int: e.g. the credit notes of an invoice), related_document_ids (non-empty
     * list of ints), offer_statuses (non-empty list of stored offer statuses), valid_until_before
     * ('Y-m-d': valid_until set and earlier) and valid_until_from ('Y-m-d': no valid_until or on/after;
     * together they split offers into expired and still valid), q (number or
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
     * R27: payment state of every ISSUED INVOICE in ONE aggregate query (no per-row lookups), with
     * exactly the semantics of DocumentService::paymentSummary() on the Berlin date of $today:
     * paid = SUM(payments), credited = SUM(ABS(gross)) of ISSUED credit notes of the invoice
     * (drafts never count), balance = max(0, gross - paid - credited), pay_status =
     * credited (credited > 0 and >= gross) | paid (balance 0) | overdue (due_date before today;
     * due today is not overdue; wins over partial) | partial (paid > 0) | open.
     * Also: last_dunning_level / last_dunning_date of the invoice's ISSUED dunning letters
     * (type 'dunning', related_document_id = invoice; null when there is none).
     * Drafts, offers, credit notes and dunning letters are never rows themselves.
     *
     * Filters: status (one of PAYMENT_STATUSES; anything else matches nothing), q (number or buyer
     * name, as list()), ids (non-empty list of at most 500 document ids: only those invoices). Order: 'due' = due_date ASC, id ASC (dunning list, dashboard) or 'issued' =
     * issue_date DESC, id DESC (invoice list). Every table is company-scoped (BaseRepo::scopedReport).
     *
     * Row keys: id, number, type, status, buyer_name, customer_id, currency, lang, reverse_charge,
     * issue_date, due_date, gross_minor, paid_minor, credited_minor, balance_minor, pay_status,
     * last_dunning_level (?int), last_dunning_date (?string).
     *
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function paymentRows(array $filter, \DateTimeImmutable $today, ?int $limit = null, int $offset = 0, string $order = 'due'): array
    {
        $orders = ['due' => 'due_date ASC, id ASC', 'issued' => 'issue_date DESC, id DESC'];
        if (!isset($orders[$order])) {
            throw new \InvalidArgumentException('Unknown order.');
        }
        $q = $this->paymentQuery($filter, $today);
        if ($q === null) {
            return [];
        }
        return array_map(function (array $r): array {
            $r = $this->ints($r, ['id', 'reverse_charge', 'gross_minor', 'paid_minor', 'credited_minor', 'balance_minor'], ['customer_id', 'last_dunning_level']);
            $r['last_dunning_date'] = $r['last_dunning_date'] === null ? null : substr((string) $r['last_dunning_date'], 0, 10);
            return $r;
        }, $this->scopedReport($q, $orders[$order], $limit, $offset));
    }

    /** @param array<string, mixed> $filter same as paymentRows() */
    public function paymentCount(array $filter, \DateTimeImmutable $today): int
    {
        $q = $this->paymentQuery($filter, $today);
        return $q === null ? 0 : $this->scopedReportCount($q);
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>|null the scopedReport spec, null when the filter matches nothing
     */
    private function paymentQuery(array $filter, \DateTimeImmutable $today): ?array
    {
        $outer = '1=1';
        $outerParams = [];
        if (array_key_exists('status', $filter)) {
            if (!in_array($filter['status'], self::PAYMENT_STATUSES, true)) {
                return null;
            }
            $outer = 'pay_status = ?';
            $outerParams = [$filter['status']];
        }
        [$where, $params] = $this->filterWhere(['q' => $filter['q'] ?? null]);
        if (array_key_exists('ids', $filter)) {
            $ids = $filter['ids'];
            if (!is_array($ids) || $ids === [] || count($ids) > 500) {
                return null;
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $where = "($where) AND id IN (" . implode(', ', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        $balance = 'CASE WHEN gross_minor - paid_minor - credited_minor > 0 THEN gross_minor - paid_minor - credited_minor ELSE 0 END';
        return [
            'table' => self::DOCS,
            'alias' => 'd',
            'columns' => 'd.id, d.number, d.type, d.status, d.buyer_name, d.customer_id, d.currency, d.lang, d.reverse_charge, d.issue_date, d.due_date, d.gross_minor',
            'subs' => [
                'paid_minor' => [self::PAYMENTS, 'p', 'COALESCE(SUM(p.amount_minor), 0)', 'p.document_id = d.id'],
                'credited_minor' => [self::DOCS, 'c', 'COALESCE(SUM(ABS(c.gross_minor)), 0)', "c.type = 'credit_note' AND c.status = 'issued' AND c.related_document_id = d.id"],
                'last_dunning_level' => [self::DOCS, 'm', 'MAX(m.dunning_level)', "m.type = 'dunning' AND m.status = 'issued' AND m.related_document_id = d.id"],
                'last_dunning_date' => [self::DOCS, 'n', 'MAX(n.issue_date)', "n.type = 'dunning' AND n.status = 'issued' AND n.related_document_id = d.id"],
            ],
            'where' => "d.type = 'invoice' AND d.status = 'issued' AND ($where)",
            'params' => $params,
            'computed' => [
                'balance_minor' => $balance,
                'pay_status' => "CASE WHEN credited_minor > 0 AND credited_minor >= gross_minor THEN 'credited'"
                    . " WHEN ($balance) = 0 THEN 'paid'"
                    . " WHEN due_date IS NOT NULL AND due_date < ? THEN 'overdue'"
                    . " WHEN paid_minor > 0 THEN 'partial' ELSE 'open' END",
            ],
            'computed_params' => [$today->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d')],
            'outer_where' => $outer,
            'outer_params' => $outerParams,
        ];
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

    /** Issue date ('Y-m-d') of the latest ISSUED document of $type of the company, null when there is none. */
    public function latestIssueDate(string $type): ?string
    {
        if (!DocType::isValid($type)) {
            return null;
        }
        $rows = $this->scopedAll(self::DOCS, "type = ? AND status = 'issued' AND issue_date IS NOT NULL", [$type], 'issue_date DESC, id DESC', 1);
        return $rows === [] ? null : substr((string) $rows[0]['issue_date'], 0, 10);
    }

    /** @return list<array<string, mixed>> lines of a document, by position */
    public function lines(int $documentId): array
    {
        return array_map(
            fn (array $r): array => $this->ints($r, self::LINE_INTS, ['item_id']),
            $this->scopedAll(self::LINES, 'document_id = ?', [$documentId], 'position ASC, id ASC')
        );
    }

    /**
     * Is (type, number) already used by a document of this company? PLAIN read on purpose (no row
     * lock, so no lock cycle with transactions holding other documents): a number committed after
     * the reader's snapshot is caught by the UNIQUE index, which the issuer handles by retrying.
     */
    public function numberTaken(string $type, string $number): bool
    {
        return $this->scopedAll(self::DOCS, 'type = ? AND number = ?', [$type, $number], '', 1) !== [];
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

    /**
     * Issued dunning letters of an invoice, oldest first (locking read when $lock).
     *
     * @return list<array<string, mixed>>
     */
    public function issuedDunnings(int $invoiceId, bool $lock = false): array
    {
        return array_map(fn (array $r): array => $this->normalize($r), $this->scopedAll(
            self::DOCS,
            "type = 'dunning' AND status = 'issued' AND related_document_id = ?",
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
            fn (array $r): array => $this->ints($r, ['id', 'company_id', 'document_id', 'amount_minor'], ['created_by', 'reverses_payment_id']),
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

    /**
     * The one column of an ISSUED offer that may change: offer_status (DocumentService validates
     * the value and the transition). Refuses anything that is not an issued offer.
     *
     * @throws DocumentNotFound
     */
    public function updateOfferStatus(int $id, string $status, string $updatedAt): void
    {
        $row = $this->scopedOne(self::DOCS, $id);
        if ($row === null || $row['type'] !== DocType::OFFER || $row['status'] !== 'issued') {
            throw new DocumentNotFound();
        }
        $this->scopedUpdate(self::DOCS, $id, ['offer_status' => $status, 'updated_at' => $updatedAt]);
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
        if (array_key_exists('types', $filter)) {
            $types = $filter['types'];
            if (!is_array($types) || $types === []) {
                return ['1=0', []];
            }
            foreach ($types as $t) {
                if (!is_string($t) || !DocType::isValid($t)) {
                    return ['1=0', []];
                }
            }
            $types = array_values(array_unique($types));
            $conds[] = 'type IN (' . implode(', ', array_fill(0, count($types), '?')) . ')';
            array_push($params, ...$types);
        }
        if (isset($filter['status'])) {
            if (!in_array($filter['status'], self::STATUSES, true)) {
                return ['1=0', []];
            }
            $conds[] = 'status = ?';
            $params[] = $filter['status'];
        }
        if (isset($filter['related_document_id'])) {
            $conds[] = 'related_document_id = ?';
            $params[] = (int) $filter['related_document_id'];
        }
        if (array_key_exists('related_document_ids', $filter)) {
            $ids = $filter['related_document_ids'];
            if (!is_array($ids) || $ids === []) {
                return ['1=0', []];
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $conds[] = 'related_document_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        if (array_key_exists('offer_statuses', $filter)) {
            $statuses = $filter['offer_statuses'];
            if (!is_array($statuses) || $statuses === [] || array_diff($statuses, self::OFFER_STATUSES) !== []) {
                return ['1=0', []];
            }
            $statuses = array_values(array_unique($statuses));
            $conds[] = 'offer_status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...$statuses);
        }
        foreach (['valid_until_before' => 'valid_until IS NOT NULL AND valid_until < ?', 'valid_until_from' => '(valid_until IS NULL OR valid_until >= ?)'] as $key => $cond) {
            if (isset($filter[$key])) {
                if (!is_string($filter[$key]) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $filter[$key]) !== 1) {
                    return ['1=0', []];
                }
                $conds[] = $cond;
                $params[] = $filter[$key];
            }
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
