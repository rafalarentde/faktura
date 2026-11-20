<?php
declare(strict_types=1);

namespace Tests\Repo;

use App\Core\Db;
use App\Domain\DocumentService;
use App\Domain\NumberSequences;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Tenant\CompanyContext;
use PHPUnit\Framework\TestCase;

/**
 * R27: the ONE aggregate payment-status query (DocumentRepo::paymentRows/paymentCount) must agree
 * exactly with DocumentService::paymentSummary() for every issued invoice, and must never list
 * drafts, offers, credit notes or dunning letters.
 */
final class PaymentAggregateTest extends TestCase
{
    private const NOW = 1_795_514_400; // 2026-11-24 10:00:00 UTC (Berlin 11:00)

    private Db $db;
    private int $userId;
    private int $companyA;
    private int $companyB;
    private DocumentService $svc;
    private DocumentRepo $repo;
    private int $customer;

    protected function setUp(): void
    {
        $this->db = \TestDb::fresh();
        $this->db->exec('INSERT INTO users (email, password_hash) VALUES (?, ?)', ['o@example.com', 'x']);
        $this->userId = $this->db->lastId();
        $this->companyA = $this->company('A GmbH');
        $this->companyB = $this->company('B GmbH');
        $this->svc = $this->svc($this->companyA);
        $this->repo = new DocumentRepo($this->db, $this->ctx($this->companyA));
        $this->customer = $this->customer($this->companyA, 'Kunde GmbH');
    }

    private function company(string $name): int
    {
        $this->db->exec(
            'INSERT INTO companies (name, address, tax_number, vat_id, iban, access_status) VALUES (?, ?, ?, ?, ?, ?)',
            [$name, "Hauptstr. 1\n10115 Berlin", '27/123/45678', 'DE123456789', 'DE89370400440532013000', 'active']
        );
        $id = $this->db->lastId();
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$id, $this->userId, 'owner']);
        return $id;
    }

    private function ctx(int $companyId): CompanyContext
    {
        return CompanyContext::forUser($this->db, $this->userId, $companyId);
    }

    private function svc(int $companyId): DocumentService
    {
        $ctx = $this->ctx($companyId);
        $clock = fn (): int => self::NOW;
        return new DocumentService($this->db, $ctx, new NumberSequences($this->db, $ctx, $clock), new DocumentRepo($this->db, $ctx), new CustomerRepo($this->db, $ctx), $clock);
    }

    private function customer(int $companyId, string $name, string $currency = 'EUR', string $country = 'DE'): int
    {
        return (new CustomerRepo($this->db, $this->ctx($companyId)))->save([
            'name' => $name, 'address' => "Weg 1\n10115 Berlin", 'country' => $country, 'is_company' => '1', 'payment_days' => '14',
            'default_lang' => 'de', 'default_currency' => $currency,
        ]);
    }

    private function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . self::NOW);
    }

    private function day(string $d): \DateTimeImmutable
    {
        return new \DateTimeImmutable($d . ' 12:00:00', new \DateTimeZone('Europe/Berlin'));
    }

    /** Issued invoice of $unit minor (19 % VAT on top), due on $due. */
    private function invoice(string $due, int $unit = 10000, ?DocumentService $svc = null, ?int $customer = null, array $extra = []): int
    {
        $svc ??= $this->svc;
        $id = $svc->createDraft('invoice', $extra + [
            'customer_id' => $customer ?? $this->customer, 'issue_date' => '2026-10-01', 'due_date' => $due,
            'lines' => [['name' => 'Leistung', 'unit' => 'hour', 'qty_milli' => 1000, 'unit_minor' => $unit, 'discount_bp' => 0, 'vat_bp' => 1900]],
        ]);
        $svc->issue($id, $this->at());
        return $id;
    }

    /** Issued (or draft) credit note over $unit net for $invoice. */
    private function credit(int $invoice, int $unit, bool $issue = true): int
    {
        $cn = $this->svc->createCorrection($invoice);
        $this->svc->updateDraft($cn, ['issue_date' => '2026-11-01', 'lines' => [['name' => 'Gutschrift', 'unit' => 'hour', 'qty_milli' => 1000, 'unit_minor' => $unit, 'discount_bp' => 0, 'vat_bp' => 1900]]]);
        if ($issue) {
            $this->svc->issue($cn, $this->at());
        }
        return $cn;
    }

    private function pay(int $invoice, int $amount): void
    {
        $this->svc->addPayment($invoice, $amount, $this->day('2026-11-02'));
    }

    /** @return array<int, array<string, mixed>> aggregate rows by id */
    private function byId(array $filter = []): array
    {
        $out = [];
        foreach ($this->repo->paymentRows($filter, $this->at()) as $r) {
            $out[$r['id']] = $r;
        }
        return $out;
    }

    /** @return array<string, int> name => id of every invoice of the fixture matrix */
    private function matrix(): array
    {
        $pln = $this->customer($this->companyA, 'Polska Sp. z o.o.', 'PLN');
        $usd = $this->customer($this->companyA, 'Yankee Inc.', 'USD', 'US');
        $m = [];
        $m['open'] = $this->invoice('2026-12-10');                    // 119,00 gross
        $m['due_today'] = $this->invoice('2026-11-24');
        $m['due_tomorrow'] = $this->invoice('2026-11-25');
        $m['partial'] = $this->invoice('2026-12-10');
        $this->pay($m['partial'], 4000);
        $m['overdue'] = $this->invoice('2026-11-23');
        $m['overdue_partial'] = $this->invoice('2026-10-28');
        $this->pay($m['overdue_partial'], 100);
        $m['paid'] = $this->invoice('2026-10-15');
        $this->pay($m['paid'], 11900);
        $m['paid_in_two'] = $this->invoice('2026-12-10');
        $this->pay($m['paid_in_two'], 11899);
        $this->pay($m['paid_in_two'], 1);
        $m['credited_full'] = $this->invoice('2026-10-15');
        $this->credit($m['credited_full'], 10000);
        $m['credited_in_two'] = $this->invoice('2026-10-15');
        $this->credit($m['credited_in_two'], 4000);
        $this->credit($m['credited_in_two'], 6000);
        $m['credited_partial_overdue'] = $this->invoice('2026-10-15');
        $this->credit($m['credited_partial_overdue'], 3000);
        $m['credited_partial_paid_rest'] = $this->invoice('2026-12-10');
        $this->credit($m['credited_partial_paid_rest'], 3000);
        $this->pay($m['credited_partial_paid_rest'], 11900 - 3570);
        $m['credited_partial_open'] = $this->invoice('2026-12-10');
        $this->credit($m['credited_partial_open'], 5000);
        $m['draft_credit_only'] = $this->invoice('2026-11-20');
        $this->credit($m['draft_credit_only'], 10000, false);       // a draft credit note never counts
        $m['pln_partial'] = $this->invoice('2026-12-10', 50000, null, $pln, ['eur_rate' => '4.2650']);
        $this->pay($m['pln_partial'], 100);
        $m['pln_overdue'] = $this->invoice('2026-11-01', 50000, null, $pln, ['eur_rate' => '4.2650']);
        $m['usd_paid'] = $this->invoice('2026-11-01', 7000, null, $usd, ['eur_rate' => '1.0850']);
        $this->pay($m['usd_paid'], $this->svc->balanceMinor($m['usd_paid']));
        $m['big'] = $this->invoice('2026-11-01', 99_999_999_999);
        $this->pay($m['big'], 1);
        return $m;
    }

    public function test_fixture_matrix_matches_payment_summary_exactly(): void
    {
        $m = $this->matrix();
        // noise that must not be listed or counted
        $draft = $this->svc->createDraft('invoice', ['customer_id' => $this->customer, 'due_date' => '2026-10-01',
            'lines' => [['name' => 'x', 'unit' => 'hour', 'qty_milli' => 1000, 'unit_minor' => 100, 'discount_bp' => 0, 'vat_bp' => 1900]]]);
        $offer = $this->svc->createDraft('offer', ['customer_id' => $this->customer, 'lines' => [['name' => 'x', 'unit' => 'hour', 'qty_milli' => 1000, 'unit_minor' => 100, 'discount_bp' => 0, 'vat_bp' => 1900]]]);
        $this->svc->issue($offer, $this->at());
        $foreign = $this->invoice('2026-10-01', 10000, $this->svc($this->companyB), $this->customer($this->companyB, 'Fremd GmbH'));

        $rows = $this->byId();
        $want = array_values($m);
        sort($want);
        $got = array_keys($rows);
        sort($got);
        $this->assertSame($want, $got, 'exactly the issued invoices of the company');
        foreach ([$draft, $offer, $foreign] as $absent) {
            $this->assertArrayNotHasKey($absent, $rows);
        }
        foreach ($m as $name => $id) {
            $s = $this->svc->paymentSummary($id, $this->at());
            $r = $rows[$id];
            $this->assertSame(
                [$s['status'], $s['gross_minor'], $s['paid_minor'], $s['credited_minor'], $s['balance_minor'], $s['due_date']],
                [$r['pay_status'], $r['gross_minor'], $r['paid_minor'], $r['credited_minor'], $r['balance_minor'], $r['due_date']],
                $name
            );
        }
        $expected = [
            'open' => 'open', 'due_today' => 'open', 'due_tomorrow' => 'open', 'partial' => 'partial', 'overdue' => 'overdue',
            'overdue_partial' => 'overdue', 'paid' => 'paid', 'paid_in_two' => 'paid', 'credited_full' => 'credited', 'credited_in_two' => 'credited',
            'credited_partial_overdue' => 'overdue', 'credited_partial_paid_rest' => 'paid', 'credited_partial_open' => 'open', 'draft_credit_only' => 'overdue',
            'pln_partial' => 'partial', 'pln_overdue' => 'overdue', 'usd_paid' => 'paid', 'big' => 'overdue',
        ];
        foreach ($expected as $name => $status) {
            $this->assertSame($status, $rows[$m[$name]]['pay_status'], $name);
        }
        // every status filter returns exactly the matching ids, and the count agrees
        foreach (['open', 'partial', 'overdue', 'paid', 'credited'] as $status) {
            $want = array_values(array_filter($m, fn (int $id): bool => $rows[$id]['pay_status'] === $status));
            sort($want);
            $got = array_keys($this->byId(['status' => $status]));
            sort($got);
            $this->assertSame($want, $got, $status);
            $this->assertSame(count($want), $this->repo->paymentCount(['status' => $status], $this->at()), $status);
        }
        $this->assertSame(count($m), $this->repo->paymentCount([], $this->at()));
    }

    public function test_berlin_today_boundary(): void
    {
        $due = $this->invoice('2026-11-24');
        // 2026-11-24 23:30 UTC is already 2026-11-25 in Berlin: now overdue in both.
        $late = new \DateTimeImmutable('2026-11-24 23:30:00', new \DateTimeZone('UTC'));
        $this->assertSame('overdue', $this->svc->paymentSummary($due, $late)['status']);
        $this->assertSame('overdue', $this->repo->paymentRows([], $late)[0]['pay_status']);
        $early = new \DateTimeImmutable('2026-11-24 22:30:00', new \DateTimeZone('UTC')); // 23:30 Berlin
        $this->assertSame('open', $this->svc->paymentSummary($due, $early)['status']);
        $this->assertSame('open', $this->repo->paymentRows([], $early)[0]['pay_status']);
    }

    public function test_last_issued_dunning_level_and_date_per_invoice(): void
    {
        $a = $this->invoice('2026-10-15');
        $b = $this->invoice('2026-10-15');
        $c = $this->invoice('2026-10-15');
        $insert = function (int $company, int $related, string $status, int $level, string $date, string $type = 'dunning'): void {
            $this->db->exec(
                "INSERT INTO documents (company_id, type, status, related_document_id, dunning_level, issue_date, lang, currency) VALUES (?, ?, ?, ?, ?, ?, 'de', 'EUR')",
                [$company, $type, $status, $related, $level, $date]
            );
        };
        $insert($this->companyA, $a, 'issued', 0, '2026-10-25');
        $insert($this->companyA, $a, 'issued', 1, '2026-11-05');
        $insert($this->companyA, $a, 'draft', 3, '2026-11-20');       // drafts never count
        $insert($this->companyB, $b, 'issued', 2, '2026-11-06');      // another company's row
        $insert($this->companyA, $b, 'issued', 2, '2026-11-06', 'credit_note'); // not a dunning letter
        $rows = $this->byId();
        $this->assertSame([1, '2026-11-05'], [$rows[$a]['last_dunning_level'], $rows[$a]['last_dunning_date']]);
        $this->assertSame([null, null], [$rows[$b]['last_dunning_level'], $rows[$b]['last_dunning_date']]);
        $this->assertSame([null, null], [$rows[$c]['last_dunning_level'], $rows[$c]['last_dunning_date']]);
        $this->assertCount(3, $rows, 'dunning rows are never listed themselves');
    }

    public function test_order_pagination_and_count(): void
    {
        $ids = [];
        foreach (['2026-11-03', '2026-11-01', '2026-11-02', '2026-11-01', '2026-11-05'] as $due) {
            $ids[] = $this->invoice($due);
        }
        $expected = [$ids[1], $ids[3], $ids[2], $ids[0], $ids[4]]; // due_date ASC, then id ASC
        $this->assertSame($expected, array_column($this->repo->paymentRows(['status' => 'overdue'], $this->at()), 'id'));
        $this->assertSame([$ids[2], $ids[0]], array_column($this->repo->paymentRows(['status' => 'overdue'], $this->at(), 2, 2), 'id'));
        $this->assertSame([], $this->repo->paymentRows(['status' => 'overdue'], $this->at(), 2, 10));
        $this->assertSame(5, $this->repo->paymentCount(['status' => 'overdue'], $this->at()));
        // the invoice list's order: issue_date DESC, id DESC (all issued on the same day here)
        $this->assertSame(array_reverse($ids), array_column($this->repo->paymentRows([], $this->at(), null, 0, 'issued'), 'id'));
    }

    public function test_unknown_status_matches_nothing_and_bad_order_is_refused(): void
    {
        $this->invoice('2026-11-01');
        foreach (['draft', 'issued', 'bogus', '', ['overdue']] as $bad) {
            $this->assertSame([], $this->repo->paymentRows(['status' => $bad], $this->at()), var_export($bad, true));
            $this->assertSame(0, $this->repo->paymentCount(['status' => $bad], $this->at()));
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->paymentRows([], $this->at(), null, 0, 'id; DROP TABLE documents');
    }

    public function test_search_by_buyer_name_and_number_with_special_characters(): void
    {
        $obrien = $this->invoice('2026-11-01', 10000, null, $this->customer($this->companyA, "O'Brien & Söhne"));
        $dash = $this->invoice('2026-11-01', 10000, null, $this->customer($this->companyA, 'a--b [x] #1 100% under_score'));
        $this->assertSame([$obrien], array_column($this->repo->paymentRows(['q' => "o'brien"], $this->at()), 'id'));
        $this->assertSame([$obrien], array_column($this->repo->paymentRows(['q' => 'SÖHNE'], $this->at()), 'id'));
        foreach (['a--b', '[x]', '#1', 'under'] as $q) {
            $this->assertSame([$dash], array_column($this->repo->paymentRows(['q' => $q, 'status' => 'overdue'], $this->at()), 'id'), $q);
        }
        foreach (['%', '_', "'; --", '\\', '/*', 'UNION SELECT 1', '?1'] as $q) {
            $rows = $this->repo->paymentRows(['q' => $q], $this->at()); // never throws
            $this->assertIsArray($rows, $q);
            $this->assertSame(count($rows), $this->repo->paymentCount(['q' => $q], $this->at()), $q);
        }
        $number = (string) $this->repo->row($obrien)['number'];
        $this->assertSame([$obrien], array_column($this->repo->paymentRows(['q' => $number], $this->at()), 'id'));
    }

    public function test_company_scoping(): void
    {
        $this->invoice('2026-11-01');
        $repoB = new DocumentRepo($this->db, $this->ctx($this->companyB));
        $this->assertSame([], $repoB->paymentRows([], $this->at()));
        $this->assertSame(0, $repoB->paymentCount([], $this->at()));
        // a payment row of company B pointing at A's invoice id never counts for A
        $id = (int) $this->repo->paymentRows([], $this->at())[0]['id'];
        $this->db->exec("INSERT INTO payments (company_id, document_id, amount_minor, paid_on) VALUES (?, ?, 5000, '2026-11-02')", [$this->companyB, $id]);
        $this->assertSame(0, $this->repo->paymentRows([], $this->at())[0]['paid_minor']);
        $this->assertSame($this->svc->paymentSummary($id, $this->at())['balance_minor'], $this->repo->paymentRows([], $this->at())[0]['balance_minor']);
    }
}
