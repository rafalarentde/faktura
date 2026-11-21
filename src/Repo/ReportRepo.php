<?php
declare(strict_types=1);

namespace App\Repo;

use App\Core\Db;
use App\Domain\DocType;
use App\Domain\OfferStatus;
use App\Tenant\CompanyContext;

/**
 * Figures of the dashboard (Task 22), all scoped to the context company.
 *
 * Money rules: every sum is grouped by currency (EUR is never added to PLN or USD); only issued
 * INVOICES are summed, through DocumentRepo::paymentRows (the one aggregate query, same balance
 * and overdue semantics as DocumentService::paymentSummary: credit notes reduce the balance, due
 * today is not overdue, drafts never count). Offers, credit notes as documents and dunning letters
 * (their claim columns are never read) carry no revenue.
 */
final class ReportRepo extends BaseRepo
{
    private const BERLIN = 'Europe/Berlin';
    private const PAYMENTS = 'payments';
    private const DOCS = 'documents';
    /** Kinds shown in the recent-documents panel (dunning letters are not). */
    private const RECENT_TYPES = [DocType::INVOICE, DocType::CREDIT_NOTE, DocType::OFFER];

    private DocumentRepo $docs;

    public function __construct(Db $db, CompanyContext $ctx)
    {
        parent::__construct($db, $ctx);
        $this->docs = new DocumentRepo($db, $ctx);
    }

    /**
     * Every card is a map currency => ['minor' => int, 'count' => int] with only the currencies that
     * have at least one invoice/payment in it:
     *  - open: issued invoices with balance > 0 (overdue ones included), sum of the balances
     *  - overdue: balance > 0 and due_date before the Berlin day of $today
     *  - due_in_7: balance > 0 and due_date from today to today + 7 days, both inclusive
     *  - paid_month: payments with paid_on in the Berlin calendar month of $today (first and last
     *    day inclusive) on invoices of the company, also when credited later; count = payments
     * primaryCurrency is the company's default currency; otherCurrencies lists the other currencies
     * with a figure in any card (sorted).
     *
     * @return array{open: array<string, array{minor: int, count: int}>, overdue: array<string, array{minor: int, count: int}>,
     *               due_in_7: array<string, array{minor: int, count: int}>, paid_month: array<string, array{minor: int, count: int}>,
     *               primaryCurrency: string, otherCurrencies: list<string>}
     */
    public function totals(\DateTimeImmutable $today): array
    {
        $day = $this->berlinDay($today);
        $limit = (new \DateTimeImmutable($day))->modify('+7 days')->format('Y-m-d');
        $out = ['open' => [], 'overdue' => [], 'due_in_7' => [], 'paid_month' => []];
        $currency = [];
        foreach ($this->docs->paymentRows([], $today) as $r) {
            $currency[$r['id']] = (string) $r['currency'];
            $balance = (int) $r['balance_minor'];
            if ($balance <= 0) {
                continue;
            }
            $cur = (string) $r['currency'];
            $due = $r['due_date'] === null ? null : substr((string) $r['due_date'], 0, 10);
            self::add($out['open'], $cur, $balance);
            if ($due !== null && $due < $day) {
                self::add($out['overdue'], $cur, $balance);
            } elseif ($due !== null && $due <= $limit) {
                self::add($out['due_in_7'], $cur, $balance);
            }
        }
        [$from, $to] = $this->month($day);
        // Payments are read on their own (no join, so no fan-out); the currency comes from the invoice.
        // A payment whose document is not an issued invoice of this company is skipped.
        foreach ($this->scopedAll(self::PAYMENTS, 'paid_on >= ? AND paid_on <= ?', [$from, $to]) as $p) {
            $cur = $currency[(int) $p['document_id']] ?? null;
            if ($cur !== null) {
                self::add($out['paid_month'], $cur, (int) $p['amount_minor']);
            }
        }
        $primary = (string) ($this->ctx->company()['default_currency'] ?? 'EUR');
        $others = [];
        foreach ($out as $card) {
            foreach (array_keys($card) as $cur) {
                if ($cur !== $primary) {
                    $others[$cur] = true;
                }
            }
        }
        ksort($others);
        return $out + ['primaryCurrency' => $primary, 'otherCurrencies' => array_keys($others)];
    }

    /**
     * Latest documents of kinds invoice, credit_note and offer (dunning letters excluded), newest
     * first by COALESCE(issue_date, UTC creation date), then id. $kind 'invoice' = invoices and
     * credit notes, 'offer' = offers; anything else = all.
     *
     * Row keys: id, type, number (null for drafts), buyer_name, issue_date, due_date (invoices only,
     * else null), gross_minor (signed as stored), currency, status (invoices: the payment status of the
     * aggregate, credit notes: draft|issued; offers: DocumentService::offerStatus), href.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 10, ?string $kind = null, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('now');
        $types = match ($kind) {
            'invoice' => [DocType::INVOICE, DocType::CREDIT_NOTE],
            'offer' => [DocType::OFFER],
            default => self::RECENT_TYPES,
        };
        $rows = $this->scopedReport([
            'table' => self::DOCS,
            'alias' => 'd',
            'columns' => 'd.id, d.type, d.status, d.number, d.buyer_name, d.issue_date, d.due_date, d.gross_minor, d.currency, d.offer_status, d.valid_until, d.created_at',
            'where' => 'd.type IN (' . implode(', ', array_fill(0, count($types), '?')) . ')',
            'params' => $types,
            'computed' => ['sort_date' => 'COALESCE(issue_date, substr(created_at, 1, 10))'],
        ], 'sort_date DESC, id DESC', max(0, $limit));

        $invoiceIds = [];
        foreach ($rows as $r) {
            if ($r['type'] === DocType::INVOICE && $r['status'] === 'issued') {
                $invoiceIds[] = (int) $r['id'];
            }
        }
        $pay = [];
        if ($invoiceIds !== []) {
            foreach ($this->docs->paymentRows(['ids' => $invoiceIds], $today) as $p) {
                $pay[$p['id']] = (string) $p['pay_status'];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $type = (string) $r['type'];
            $id = (int) $r['id'];
            if ($type === DocType::OFFER) {
                $status = OfferStatus::of($r, $today);
            } elseif ($type === DocType::INVOICE) {
                $status = $r['status'] === 'issued' ? ($pay[$id] ?? 'issued') : 'draft';
            } else {
                $status = $r['status'] === 'issued' ? 'issued' : 'draft';
            }
            $out[] = [
                'id' => $id,
                'type' => $type,
                'number' => $r['number'] === null || $r['number'] === '' ? null : (string) $r['number'],
                'buyer_name' => (string) ($r['buyer_name'] ?? ''),
                'issue_date' => $r['issue_date'] === null ? null : substr((string) $r['issue_date'], 0, 10),
                'due_date' => $type === DocType::INVOICE && $r['due_date'] !== null ? substr((string) $r['due_date'], 0, 10) : null,
                'gross_minor' => (int) $r['gross_minor'],
                'currency' => (string) $r['currency'],
                'status' => $status,
                'href' => ($type === DocType::OFFER ? '/offers/' : '/invoices/') . $id,
            ];
        }
        return $out;
    }

    /** Does the company have any invoice, credit note or offer (drafts included)? */
    public function hasDocuments(): bool
    {
        return $this->scopedAll(self::DOCS, 'type IN (' . implode(', ', array_fill(0, count(self::RECENT_TYPES), '?')) . ')', self::RECENT_TYPES, '', 1) !== [];
    }

    /**
     * Issued documents (invoices, credit notes, offers, dunning letters) whose issue_date lies in
     * the Berlin calendar month of $today. Informational (plan box); drafts never count.
     */
    public function issuedInMonth(\DateTimeImmutable $today): int
    {
        [$from, $to] = $this->month($this->berlinDay($today));
        return $this->scopedCount(
            self::DOCS,
            "status = 'issued' AND type IN ('invoice', 'credit_note', 'offer', 'dunning') AND issue_date >= ? AND issue_date <= ?",
            [$from, $to]
        );
    }

    /** @param array<string, array{minor: int, count: int}> $card */
    private static function add(array &$card, string $currency, int $minor): void
    {
        $card[$currency] = ['minor' => ($card[$currency]['minor'] ?? 0) + $minor, 'count' => ($card[$currency]['count'] ?? 0) + 1];
    }

    private function berlinDay(\DateTimeImmutable $today): string
    {
        return $today->setTimezone(new \DateTimeZone(self::BERLIN))->format('Y-m-d');
    }

    /** @return array{0: string, 1: string} first and last day of the month of the Y-m-d $day */
    private function month(string $day): array
    {
        $first = new \DateTimeImmutable(substr($day, 0, 7) . '-01');
        return [$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
    }
}
