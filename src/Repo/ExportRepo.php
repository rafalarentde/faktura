<?php
declare(strict_types=1);

namespace App\Repo;

/**
 * Read-only, paged access to everything of the context company that the data export writes
 * (Exporter). Every read goes through the BaseRepo scoped helpers or, where a query needs raw
 * SQL, carries `company_id = ?` bound to the context company. Nothing here ever selects a secret
 * column of `users` (password_hash, TOTP secret/step) or `companies` (smtp_pass_enc is dropped in
 * company()), and users are reached only through the company's own memberships.
 *
 * Rows come back with integer columns as int; text and dates stay strings.
 */
final class ExportRepo extends BaseRepo
{
    /** Columns of `companies` that may leave the system: no smtp_pass_enc, no logo path, no access status or period. */
    private const COMPANY_COLUMNS = ['id', 'name', 'address', 'tax_number', 'vat_id', 'iban', 'bic', 'bank', 'default_lang', 'default_currency',
        'default_payment_days', 'intro_text', 'outro_text', 'footer_text', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_from_name', 'smtp_from_addr',
        'dunning_d0', 'dunning_d1', 'dunning_d2', 'dunning_d3', 'dunning_base_rate_bp', 'dunning_b2b_surcharge_bp', 'dunning_b2c_surcharge_bp',
        'dunning_flat_minor', 'dunning_fee_minor', 'plan', 'created_at'];
    private const COMPANY_INTS = ['id', 'default_payment_days', 'smtp_port', 'dunning_d0', 'dunning_d1', 'dunning_d2', 'dunning_d3',
        'dunning_base_rate_bp', 'dunning_b2b_surcharge_bp', 'dunning_b2c_surcharge_bp', 'dunning_flat_minor', 'dunning_fee_minor'];

    private const DOCUMENT_INTS = ['id', 'related_document_id', 'customer_id', 'reverse_charge', 'net_minor', 'vat_minor', 'gross_minor',
        'vat_eur_minor', 'created_by', 'dunning_level', 'dunning_outstanding_minor', 'dunning_interest_minor', 'dunning_interest_days',
        'dunning_interest_rate_bp', 'dunning_flat_fee_minor', 'dunning_fee_minor', 'dunning_claim_minor'];
    private const LINE_INTS = ['id', 'document_id', 'position', 'item_id', 'qty_milli', 'unit_minor', 'discount_bp', 'vat_bp', 'net_minor'];
    private const PAYMENT_INTS = ['id', 'document_id', 'amount_minor', 'created_by', 'reverses_payment_id'];
    private const EMAIL_INTS = ['id', 'document_id', 'sent_by'];
    private const CUSTOMER_INTS = ['id', 'is_company', 'payment_days'];
    private const ITEM_INTS = ['id', 'price_minor', 'vat_bp', 'is_active'];
    private const NAME_INTS = ['id', 'item_id'];

    /** Types that have a PDF. */
    private const PDF_TYPES = "type IN ('invoice', 'credit_note', 'offer', 'dunning')";

    /** @return array<string, mixed> the company row without secrets and access internals */
    public function company(): array
    {
        $row = $this->db->one('SELECT * FROM companies WHERE id = ?', [$this->ctx->id()]) ?? [];
        $out = [];
        foreach (self::COMPANY_COLUMNS as $c) {
            $out[$c] = $row[$c] ?? null;
        }
        $out = $this->ints($out, self::COMPANY_INTS);
        $out['has_logo'] = ($row['logo_path'] ?? null) !== null && $row['logo_path'] !== '';
        $out['smtp_password_set'] = ($row['smtp_pass_enc'] ?? null) !== null && $row['smtp_pass_enc'] !== '';
        return $out;
    }

    /**
     * Members of the company: id, email, role, ui_lang. Nothing else (no hash, no 2FA data).
     *
     * @return list<array{id: int, email: string, role: string, ui_lang: string}>
     */
    public function members(): array
    {
        $out = [];
        foreach ($this->scopedAll('company_users', '1=1', [], 'user_id ASC') as $m) {
            $u = $this->db->one('SELECT id, email, ui_lang FROM users WHERE id = ?', [(int) $m['user_id']]);
            if ($u !== null) {
                $out[] = ['id' => (int) $u['id'], 'email' => (string) $u['email'], 'role' => (string) $m['role'], 'ui_lang' => (string) $u['ui_lang']];
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function customers(int $afterId, int $limit): array
    {
        return $this->page('customers', $afterId, $limit, self::CUSTOMER_INTS);
    }

    /** @return list<array<string, mixed>> */
    public function items(int $afterId, int $limit): array
    {
        return $this->page('items', $afterId, $limit, self::ITEM_INTS);
    }

    /** @param list<int> $itemIds @return list<array<string, mixed>> */
    public function itemNames(array $itemIds): array
    {
        return $this->in('item_names', 'item_id', $itemIds, 'id ASC', self::NAME_INTS);
    }

    /** @return list<array<string, mixed>> */
    public function numberFormats(): array
    {
        return array_map(fn (array $r): array => $this->ints($this->drop($r), ['id', 'reset_yearly', 'updated_at']), $this->scopedAll('number_formats', '1=1', [], 'id ASC'));
    }

    /** @return list<array<string, mixed>> */
    public function numberCounters(): array
    {
        return array_map(fn (array $r): array => $this->ints($this->drop($r), ['id', 'period', 'last_value', 'last_year']), $this->scopedAll('number_counters', '1=1', [], 'id ASC'));
    }

    /**
     * One page of documents ordered by id. $year null = every document including drafts; a year =
     * ISSUED documents whose issue date lies in that year. Rows still hold the JSON snapshots as text.
     *
     * @return list<array<string, mixed>>
     */
    public function documents(?int $year, int $afterId, int $limit): array
    {
        [$where, $params] = $this->documentFilter($year);
        $rows = $this->scopedAll('documents', "id > ? AND $where", [$afterId, ...$params], 'id ASC', $limit);
        return array_map(fn (array $r): array => $this->ints($this->drop($r), self::DOCUMENT_INTS), $rows);
    }

    public function countDocuments(?int $year): int
    {
        [$where, $params] = $this->documentFilter($year);
        return $this->scopedCount('documents', $where, $params);
    }

    /** Issued documents of a type that has a PDF (what the export would render). */
    public function countPdfDocuments(?int $year): int
    {
        [$where, $params] = $this->documentFilter($year);
        return $this->scopedCount('documents', "status = 'issued' AND " . self::PDF_TYPES . " AND $where", $params);
    }

    /** @param list<int> $documentIds @return list<array<string, mixed>> */
    public function lines(array $documentIds): array
    {
        return $this->in('document_lines', 'document_id', $documentIds, 'document_id ASC, position ASC, id ASC', self::LINE_INTS);
    }

    /** @param list<int> $documentIds @return list<array<string, mixed>> */
    public function payments(array $documentIds): array
    {
        return $this->in('payments', 'document_id', $documentIds, 'document_id ASC, paid_on ASC, id ASC', self::PAYMENT_INTS);
    }

    /** @param list<int> $documentIds @return list<array<string, mixed>> */
    public function emailLog(array $documentIds): array
    {
        return $this->in('email_log', 'document_id', $documentIds, 'id ASC', self::EMAIL_INTS);
    }

    /**
     * Sum of the absolute gross amounts of the ISSUED credit notes per invoice id.
     *
     * @param list<int> $invoiceIds
     * @return array<int, int> invoice id => credited minor units
     */
    public function creditedByInvoice(array $invoiceIds): array
    {
        $sum = [];
        foreach ($this->in('documents', 'related_document_id', $invoiceIds, 'id ASC', ['id', 'related_document_id', 'gross_minor'], "type = 'credit_note' AND status = 'issued'") as $r) {
            $sum[(int) $r['related_document_id']] = ($sum[(int) $r['related_document_id']] ?? 0) + abs((int) $r['gross_minor']);
        }
        return $sum;
    }

    /** @return list<int> years (newest first) with at least one issued document */
    public function documentYears(): array
    {
        $rows = $this->db->all(
            "SELECT DISTINCT SUBSTR(issue_date, 1, 4) AS y FROM documents WHERE company_id = ? AND status = 'issued' AND issue_date IS NOT NULL ORDER BY y DESC",
            [$this->ctx->id()]
        );
        return array_values(array_filter(array_map(fn (array $r): int => (int) $r['y'], $rows), fn (int $y): bool => $y > 0));
    }

    /** Date-time (UTC) of the company's latest export, from the audit log; null when there was none. */
    public function lastExportAt(): ?string
    {
        $row = $this->db->one(
            "SELECT at FROM audit_log WHERE company_id = ? AND action = 'export.create' ORDER BY id DESC LIMIT 1",
            [$this->ctx->id()]
        );
        return $row === null ? null : (string) $row['at'];
    }

    /** @return array{0: string, 1: list<scalar|null>} literal where (no company part) and its parameters */
    private function documentFilter(?int $year): array
    {
        if ($year === null) {
            return ['1=1', []];
        }
        return ["status = 'issued' AND issue_date >= ? AND issue_date <= ?", [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]];
    }

    /**
     * @param list<string> $ints
     * @return list<array<string, mixed>>
     */
    private function page(string $table, int $afterId, int $limit, array $ints): array
    {
        return array_map(fn (array $r): array => $this->ints($this->drop($r), $ints), $this->scopedAll($table, 'id > ?', [$afterId], 'id ASC', $limit));
    }

    /**
     * Rows whose $column is in $values (at most a few hundred at a time), with an optional extra literal condition.
     *
     * @param list<int> $values
     * @param list<string> $ints
     * @return list<array<string, mixed>>
     */
    private function in(string $table, string $column, array $values, string $order, array $ints, string $extra = '1=1'): array
    {
        $out = [];
        foreach (array_chunk(array_values($values), 200) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($this->scopedAll($table, "$column IN ($marks) AND $extra", $chunk, $order) as $r) {
                $out[] = $this->ints($this->drop($r), $ints);
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> the row without the tenant column */
    private function drop(array $row): array
    {
        unset($row['company_id']);
        return $row;
    }

    /** @param array<string, mixed> $row @param list<string> $ints @return array<string, mixed> */
    private function ints(array $row, array $ints): array
    {
        foreach ($ints as $c) {
            if (array_key_exists($c, $row) && $row[$c] !== null) {
                $row[$c] = (int) $row[$c];
            }
        }
        return $row;
    }
}
