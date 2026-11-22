<?php
declare(strict_types=1);

namespace Tests\Repo;

use App\Core\Db;
use App\Domain\DocumentService;
use App\Domain\NumberSequences;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Repo\ReportRepo;
use App\Tenant\CompanyContext;
use PHPUnit\Framework\TestCase;

/**
 * Dashboard figures (Task 22). Fixed clock: 2026-11-24 10:00 UTC = 11:00 Berlin, so "today" is
 * 2026-11-24 and the current month is November 2026. A 100,00 net invoice is 119,00 gross.
 */
final class ReportRepoTest extends TestCase
{
    private const NOW = 1_795_514_400;

    private Db $db;
    private int $userId;
    private int $companyA;
    private int $companyB;
    private DocumentService $svc;
    private ReportRepo $report;
    private int $customer;

    protected function setUp(): void
    {
        $this->db = \TestDb::fresh();
        $this->db->exec('INSERT INTO users (email, password_hash) VALUES (?, ?)', ['o@example.com', 'x']);
        $this->userId = $this->db->lastId();
        $this->companyA = $this->company('A GmbH');
        $this->companyB = $this->company('B GmbH');
        $this->svc = $this->svc($this->companyA);
        $this->report = new ReportRepo($this->db, $this->ctx($this->companyA));
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

    private function line(int $unit): array
    {
        return ['name' => 'Leistung', 'unit' => 'hour', 'qty_milli' => 1000, 'unit_minor' => $unit, 'discount_bp' => 0, 'vat_bp' => 1900];
    }

    private function invoice(string $due, int $unit = 10000, ?DocumentService $svc = null, ?int $customer = null, array $extra = []): int
    {
        $svc ??= $this->svc;
        $id = $svc->createDraft('invoice', $extra + [
            'customer_id' => $customer ?? $this->customer, 'issue_date' => '2026-10-01', 'due_date' => $due, 'lines' => [$this->line($unit)],
        ]);
        $svc->issue($id, $this->at());
        return $id;
    }

    private function draft(string $due = '2026-11-01'): int
    {
        return $this->svc->createDraft('invoice', ['customer_id' => $this->customer, 'due_date' => $due, 'lines' => [$this->line(10000)]]);
    }

    private function credit(int $invoice, int $unit, bool $issue = true): int
    {
        $cn = $this->svc->createCorrection($invoice);
        $this->svc->updateDraft($cn, ['issue_date' => '2026-11-01', 'lines' => [$this->line($unit)]]);
        if ($issue) {
            $this->svc->issue($cn, $this->at());
        }
        return $cn;
    }

    /** Payments dated after the fixed clock ("today") cannot go through the service (M6): insert the row directly. */
    private function pay(int $invoice, int $amount, string $day = '2026-11-02', ?DocumentService $svc = null): void
    {
        if ($day > '2026-11-24') {
            $this->payRaw($invoice, $amount, $day);
            return;
        }
        ($svc ?? $this->svc)->addPayment($invoice, $amount, $this->day($day));
    }

    private function payRaw(int $invoice, int $amount, string $day): void
    {
        $row = $this->db->one('SELECT company_id FROM documents WHERE id = ?', [$invoice]);
        $this->db->exec('INSERT INTO payments (company_id, document_id, amount_minor, paid_on) VALUES (?, ?, ?, ?)', [$row['company_id'], $invoice, $amount, $day]);
    }

    private int $dunningSeq = 0;

    private function dunning(int $invoice, int $claim = 50000, int $company = 0): void
    {
        $this->db->exec(
            "INSERT INTO documents (company_id, type, status, number, related_document_id, dunning_level, dunning_claim_minor, issue_date, lang, currency, net_minor, vat_minor, gross_minor, buyer_name)
             VALUES (?, 'dunning', 'issued', ?, ?, 1, ?, '2026-11-20', 'de', 'EUR', 0, 0, 0, 'Mahnkunde')",
            [$company ?: $this->companyA, 'MA-T-' . ++$this->dunningSeq, $invoice, $claim]
        );
    }

    /** @return array{minor: int, count: int} */
    private function eur(string $card, ?\DateTimeImmutable $today = null, ?ReportRepo $r = null): array
    {
        $t = ($r ?? $this->report)->totals($today ?? $this->at());
        return $t[$card]['EUR'] ?? ['minor' => 0, 'count' => 0];
    }

    /** @return list<array<string, mixed>> */
    private function recent(int $limit = 10, ?string $kind = null): array
    {
        return $this->report->recent($limit, $kind, $this->at());
    }

    // ---- brief tests ----

    public function test_overdue_excludes_paid_and_drafts(): void
    {
        $this->invoice('2026-11-23');                    // overdue 119,00
        $paid = $this->invoice('2026-11-01');
        $this->pay($paid, 11900);
        $this->draft('2026-11-01');                      // draft: never
        $this->invoice('2026-12-31');                    // not yet due
        $this->assertSame(['minor' => 11900, 'count' => 1], $this->eur('overdue'));
    }

    public function test_due_in_7_boundaries(): void
    {
        $this->invoice('2026-11-23');   // yesterday: overdue, not "due soon"
        $this->invoice('2026-11-24');
        $this->invoice('2026-12-01');
        $this->invoice('2026-12-02');   // the 8th day
        $this->invoice('2027-01-15');
        $this->assertSame(['minor' => 23800, 'count' => 2], $this->eur('due_in_7'));
        $this->assertSame(['minor' => 11900, 'count' => 1], $this->eur('overdue'), 'due today is not overdue');
        $this->assertSame(['minor' => 59500, 'count' => 5], $this->eur('open'), 'open includes overdue and due soon');
    }

    public function test_totals_scoped_to_company(): void
    {
        $this->invoice('2026-11-01');
        $svcB = $this->svc($this->companyB);
        $cb = $this->customer($this->companyB, 'Fremd GmbH');
        $this->invoice('2026-11-01', 1_000_000, $svcB, $cb);
        $b = $this->invoice('2026-12-01', 5000, $svcB, $cb);
        $this->pay($b, 100, '2026-11-03', $svcB);
        $this->assertSame(['minor' => 11900, 'count' => 1], $this->eur('open'));
        $this->assertSame(['minor' => 0, 'count' => 0], $this->eur('paid_month'));
        $rb = new ReportRepo($this->db, $this->ctx($this->companyB));
        $this->assertSame(['minor' => 1_190_000 + 5950 - 100, 'count' => 2], $this->eur('open', null, $rb));
        $this->assertSame(['minor' => 100, 'count' => 1], $this->eur('paid_month', null, $rb));
    }

    public function test_other_currency_not_summed_into_eur(): void
    {
        $pln = $this->customer($this->companyA, 'Polska Sp. z o.o.', 'PLN', 'PL');
        $usd = $this->customer($this->companyA, 'Yankee Inc.', 'USD', 'US');
        $this->invoice('2026-11-01');
        $this->invoice('2026-11-01', 50000, null, $pln, ['eur_rate' => '4.2650']);
        $this->invoice('2026-11-01', 7000, null, $usd, ['eur_rate' => '1.0850']);
        $t = $this->report->totals($this->at());
        $this->assertSame('EUR', $t['primaryCurrency']);
        $this->assertSame(['EUR' => ['minor' => 11900, 'count' => 1], 'PLN' => ['minor' => 59500, 'count' => 1], 'USD' => ['minor' => 8330, 'count' => 1]], $t['open']);
        $this->assertEqualsCanonicalizing(['PLN', 'USD'], $t['otherCurrencies']);
        $this->assertSame($t['open'], $t['overdue']);
    }

    // ---- balances ----

    public function test_open_includes_overdue_and_partial_payments_reduce_balances(): void
    {
        $partial = $this->invoice('2026-12-20');
        $this->pay($partial, 4000);
        $overduePartial = $this->invoice('2026-11-10');
        $this->pay($overduePartial, 900);
        $this->assertSame(['minor' => 7900 + 11000, 'count' => 2], $this->eur('open'));
        $this->assertSame(['minor' => 11000, 'count' => 1], $this->eur('overdue'));
        $this->assertSame(['minor' => 0, 'count' => 0], $this->eur('due_in_7'));
    }

    public function test_paid_and_credited_invoices_leave_open_overdue_and_due_soon(): void
    {
        $paid = $this->invoice('2026-11-26');
        $this->pay($paid, 11900);
        $credited = $this->invoice('2026-11-26');
        $this->credit($credited, 10000);
        $creditedInTwo = $this->invoice('2026-11-10');
        $this->credit($creditedInTwo, 4000);
        $this->credit($creditedInTwo, 6000);
        $paidRest = $this->invoice('2026-11-10');
        $this->credit($paidRest, 3000);
        $this->pay($paidRest, 11900 - 3570);
        foreach (['open', 'overdue', 'due_in_7'] as $card) {
            $this->assertSame(['minor' => 0, 'count' => 0], $this->eur($card), $card);
        }
        $partlyCredited = $this->invoice('2026-11-26');
        $this->credit($partlyCredited, 5000);                       // 119,00 - 59,50
        $this->assertSame(['minor' => 5950, 'count' => 1], $this->eur('open'));
        $this->assertSame(['minor' => 5950, 'count' => 1], $this->eur('due_in_7'));
    }

    public function test_drafts_offers_credit_notes_and_dunning_letters_never_counted(): void
    {
        $inv = $this->invoice('2026-11-01');
        $before = $this->report->totals($this->at());
        $this->assertSame(['minor' => 11900, 'count' => 1], $before['overdue']['EUR']);

        $this->draft();
        $offer = $this->svc->createDraft('offer', ['customer_id' => $this->customer, 'lines' => [$this->line(500000)]]);
        $this->svc->issue($offer, $this->at());
        $this->credit($this->invoice('2026-12-30'), 1000, false);   // a draft credit note
        $afterNoise = $this->report->totals($this->at());
        $this->assertSame(['minor' => 11900 + 11900, 'count' => 2], $afterNoise['open']['EUR']);
        $this->assertSame($before['overdue'], $afterNoise['overdue']);

        $this->dunning($inv, 77777);
        $this->dunning($inv, 12345);
        $this->dunning($inv, 99999, $this->companyB);
        $this->assertEquals($afterNoise, $this->report->totals($this->at()), 'dunning letters change nothing');
        $this->assertSame(['minor' => 0, 'count' => 0], $this->eur('paid_month'));
    }

    // ---- paid_month ----

    public function test_paid_month_boundaries_in_berlin(): void
    {
        $inv = $this->invoice('2027-01-31', 100000);                 // gross 1.190,00
        $this->pay($inv, 100, '2026-10-31');                         // previous month
        $this->pay($inv, 200, '2026-11-01');                         // first day
        $this->pay($inv, 400, '2026-11-30');                         // last day
        $this->pay($inv, 800, '2026-12-01');                         // next month
        $this->assertSame(['minor' => 600, 'count' => 2], $this->eur('paid_month'));
        // the fixed clock is only "today": the same data seen from December and October
        $this->assertSame(['minor' => 800, 'count' => 1], $this->eur('paid_month', $this->day('2026-12-01')));
        $this->assertSame(['minor' => 100, 'count' => 1], $this->eur('paid_month', $this->day('2026-10-15')));
        // 2026-11-30 23:30 UTC is already 1 December in Berlin
        $lateUtc = new \DateTimeImmutable('2026-11-30 23:30:00', new \DateTimeZone('UTC'));
        $this->assertSame(['minor' => 800, 'count' => 1], $this->eur('paid_month', $lateUtc));
        // 2026-10-31 23:30 UTC is 1 November in Berlin: still November
        $earlyUtc = new \DateTimeImmutable('2026-10-31 23:30:00', new \DateTimeZone('UTC'));
        $this->assertSame(['minor' => 600, 'count' => 2], $this->eur('paid_month', $earlyUtc));
    }

    public function test_paid_payment_dates_via_utc_timestamps_use_berlin_days(): void
    {
        $inv = $this->invoice('2027-01-31');
        $this->svc->addPayment($inv, 300, new \DateTimeImmutable('2026-10-31 23:30:00', new \DateTimeZone('UTC')));  // 1 Nov Berlin
        $this->payRaw($inv, 500, (new \DateTimeImmutable('2026-11-30 23:30:00', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d')); // 1 Dec Berlin (future: direct row)
        $this->assertSame(['minor' => 300, 'count' => 1], $this->eur('paid_month'));
    }

    public function test_paid_month_counts_payments_on_later_credited_invoices_and_ignores_other_companies(): void
    {
        $inv = $this->invoice('2026-11-30');
        $this->pay($inv, 11900);
        $this->credit($inv, 10000);                                   // credited afterwards: the cash was received
        $svcB = $this->svc($this->companyB);
        $b = $this->invoice('2026-11-30', 10000, $svcB, $this->customer($this->companyB, 'Fremd'));
        $this->pay($b, 11900, '2026-11-02', $svcB);
        $this->assertSame(['minor' => 11900, 'count' => 1], $this->eur('paid_month'));
        $this->assertSame(['minor' => 0, 'count' => 0], $this->eur('open'));
    }

    public function test_paid_month_per_currency_and_no_fan_out(): void
    {
        $pln = $this->customer($this->companyA, 'Polska', 'PLN', 'PL');
        $a = $this->invoice('2026-12-30', 100000);                  // 1.190,00
        $this->pay($a, 1000);
        $this->pay($a, 2000, '2026-11-10');
        $this->credit($a, 1000);
        $this->credit($a, 2000);
        $this->dunning($a);
        $this->dunning($a);
        $this->dunning($a);
        $p = $this->invoice('2026-12-30', 50000, null, $pln, ['eur_rate' => '4.2650']);
        $this->pay($p, 700);
        $t = $this->report->totals($this->at());
        $this->assertSame(['EUR' => ['minor' => 3000, 'count' => 2], 'PLN' => ['minor' => 700, 'count' => 1]], $t['paid_month']);
        $this->assertSame(119000 - 3000 - 1190 - 2380, $t['open']['EUR']['minor']);
        $this->assertSame(['EUR' => 1, 'PLN' => 1], array_map(fn (array $c): int => $c['count'], $t['open']));
    }

    // ---- consistency with paymentSummary ----

    public function test_totals_match_payment_summary_over_a_fixture_matrix(): void
    {
        $pln = $this->customer($this->companyA, 'Polska', 'PLN', 'PL');
        $m = [];
        $m['open'] = $this->invoice('2026-12-10');
        $m['due_today'] = $this->invoice('2026-11-24');
        $m['day7'] = $this->invoice('2026-12-01');
        $m['day8'] = $this->invoice('2026-12-02');
        $m['partial'] = $this->invoice('2026-11-28');
        $this->pay($m['partial'], 4000);
        $m['overdue'] = $this->invoice('2026-11-23');
        $m['overdue_partial'] = $this->invoice('2026-10-28');
        $this->pay($m['overdue_partial'], 100, '2026-11-05');
        $m['paid'] = $this->invoice('2026-10-15');
        $this->pay($m['paid'], 11900, '2026-10-20');
        $m['credited'] = $this->invoice('2026-10-15');
        $this->credit($m['credited'], 10000);
        $m['credited_partial_overdue'] = $this->invoice('2026-10-15');
        $this->credit($m['credited_partial_overdue'], 3000);
        $m['draft_credit'] = $this->invoice('2026-11-20');
        $this->credit($m['draft_credit'], 10000, false);
        $m['pln'] = $this->invoice('2026-11-01', 50000, null, $pln, ['eur_rate' => '4.2650']);
        $this->pay($m['pln'], 100);
        $this->draft();

        $expect = ['open' => [], 'overdue' => [], 'due_in_7' => []];
        $today = '2026-11-24';
        foreach ($m as $id) {
            $s = $this->svc->paymentSummary($id, $this->at());
            $cur = (string) $this->db->one('SELECT currency FROM documents WHERE id = ?', [$id])['currency'];
            if ($s['balance_minor'] <= 0) {
                continue;
            }
            $add = function (string $k) use (&$expect, $cur, $s): void {
                $expect[$k][$cur]['minor'] = ($expect[$k][$cur]['minor'] ?? 0) + $s['balance_minor'];
                $expect[$k][$cur]['count'] = ($expect[$k][$cur]['count'] ?? 0) + 1;
            };
            $add('open');
            if ($s['due_date'] < $today) {
                $add('overdue');
            } elseif ($s['due_date'] <= '2026-12-01') {
                $add('due_in_7');
            }
        }
        $t = $this->report->totals($this->at());
        foreach (['open', 'overdue', 'due_in_7'] as $k) {
            $this->assertEquals($expect[$k], $t[$k], $k);
        }
        // hand-computed: open = 11900 (open) + 11900 + 11900 (due soon x2) + 11900 (day 8) ... see below
        $this->assertSame(11900 * 3 + 11900 + (11900 - 4000) + 11900 + (11900 - 100) + (11900 - 3570) + 11900, $t['open']['EUR']['minor']);
        $this->assertSame(9, $t['open']['EUR']['count']);
        $this->assertSame(['minor' => 59500 - 100, 'count' => 1], $t['open']['PLN']);
    }

    // ---- recent ----

    public function test_recent_orders_limits_and_filters_by_kind(): void
    {
        $ids = [];
        for ($i = 1; $i <= 12; $i++) {
            $id = $this->svc->createDraft('invoice', ['customer_id' => $this->customer, 'issue_date' => sprintf('2026-11-%02d', $i), 'due_date' => '2026-12-30', 'lines' => [$this->line(10000)]]);
            $this->svc->issue($id, $this->at());
            $ids[$i] = $id;
        }
        $offer = $this->svc->createDraft('offer', ['customer_id' => $this->customer, 'issue_date' => '2026-11-13', 'lines' => [$this->line(20000)]]);
        $this->svc->issue($offer, $this->at());
        $this->dunning($ids[1]);                                       // dunning letters are not listed

        $all = $this->recent();
        $this->assertCount(10, $all);
        $this->assertSame([$offer, $ids[12], $ids[11], $ids[10], $ids[9], $ids[8], $ids[7], $ids[6], $ids[5], $ids[4]], array_column($all, 'id'));
        $this->assertCount(3, $this->recent(3));
        $this->assertSame([$offer], array_column($this->recent(10, 'offer'), 'id'));
        $inv = array_column($this->recent(20, 'invoice'), 'id');
        $this->assertSame(array_reverse(array_values($ids)), $inv);
        $this->assertNotContains($offer, $inv);
        $this->assertSame([], array_diff(array_column($this->recent(50), 'type'), ['invoice', 'credit_note', 'offer']));
        $this->assertSame(array_column($all, 'id'), array_column($this->recent(10, 'bogus'), 'id'), 'unknown kind = all');
    }

    public function test_recent_rows_have_statuses_links_and_signed_amounts(): void
    {
        $open = $this->invoice('2026-12-20');
        $overdue = $this->invoice('2026-11-01');
        $paid = $this->invoice('2026-12-20');
        $this->pay($paid, 11900);
        $partial = $this->invoice('2026-12-20');
        $this->pay($partial, 100);
        $credited = $this->invoice('2026-12-20');
        $cn = $this->credit($credited, 10000);
        $draft = $this->draft();
        $draftCn = $this->credit($this->invoice('2026-12-20'), 1000, false);
        $rows = [];
        foreach ($this->recent(50) as $r) {
            $rows[$r['id']] = $r;
        }
        $this->assertSame('open', $rows[$open]['status']);
        $this->assertSame('overdue', $rows[$overdue]['status']);
        $this->assertSame('paid', $rows[$paid]['status']);
        $this->assertSame('partial', $rows[$partial]['status']);
        $this->assertSame('credited', $rows[$credited]['status']);
        $this->assertSame('draft', $rows[$draft]['status']);
        $this->assertSame(['draft', null], [$rows[$draftCn]['status'], $rows[$draftCn]['number']]);
        $this->assertSame('issued', $rows[$cn]['status']);
        $this->assertSame(-11900, $rows[$cn]['gross_minor'], 'credit note signed as stored');
        $this->assertSame(11900, $rows[$open]['gross_minor']);
        $this->assertSame('EUR', $rows[$open]['currency']);
        $this->assertSame('/invoices/' . $open, $rows[$open]['href']);
        $this->assertSame('/invoices/' . $cn, $rows[$cn]['href']);
        $this->assertSame('credit_note', $rows[$cn]['type']);
        $this->assertNull($rows[$draft]['number']);
        $this->assertSame('Kunde GmbH', $rows[$open]['buyer_name']);
        $this->assertSame('2026-12-20', $rows[$open]['due_date']);
    }

    public function test_recent_offer_statuses_and_scoping(): void
    {
        $mk = function (array $extra) {
            $id = $this->svc->createDraft('offer', $extra + ['customer_id' => $this->customer, 'lines' => [$this->line(10000)]]);
            $this->svc->issue($id, $this->at());
            return $id;
        };
        $open = $mk(['issue_date' => '2026-11-20', 'valid_until' => '2026-12-20']);
        $expired = $mk(['issue_date' => '2026-11-10', 'valid_until' => '2026-11-23']);
        $accepted = $mk(['issue_date' => '2026-11-11', 'valid_until' => '2026-11-23']);
        $this->svc->setOfferStatus($accepted, 'accepted');
        $rejected = $mk(['issue_date' => '2026-11-12']);
        $this->svc->setOfferStatus($rejected, 'rejected');
        $sent = $mk(['issue_date' => '2026-11-13']);
        $this->svc->setOfferStatus($sent, 'sent');
        $draft = $this->svc->createDraft('offer', ['customer_id' => $this->customer, 'lines' => [$this->line(100)]]);
        $svcB = $this->svc($this->companyB);
        $foreign = $svcB->createDraft('offer', ['customer_id' => $this->customer($this->companyB, 'Fremd'), 'lines' => [$this->line(100)]]);

        $rows = [];
        foreach ($this->recent(50, 'offer') as $r) {
            $rows[$r['id']] = $r;
        }
        $this->assertSame(['open', 'expired', 'accepted', 'rejected', 'sent', 'draft'], array_map(fn (int $id): string => $rows[$id]['status'], [$open, $expired, $accepted, $rejected, $sent, $draft]));
        $this->assertSame('/offers/' . $open, $rows[$open]['href']);
        $this->assertArrayNotHasKey($foreign, $rows);
        $this->assertSame([], (new ReportRepo($this->db, $this->ctx($this->companyB)))->recent(50, 'invoice', $this->at()));
    }

    public function test_recent_draft_without_issue_date_sorts_by_creation_date(): void
    {
        $issuedOld = $this->invoice('2026-12-20');                    // issue_date 2026-10-01
        $draft = $this->draft();
        $this->db->exec("UPDATE documents SET created_at = '2026-11-20 08:00:00' WHERE id = ?", [$draft]);
        $this->assertSame([$draft, $issuedOld], array_column($this->recent(), 'id'));
        $this->db->exec("UPDATE documents SET created_at = '2026-09-20 08:00:00' WHERE id = ?", [$draft]);
        $this->assertSame([$issuedOld, $draft], array_column($this->recent(), 'id'));
    }

    // ---- misc ----

    public function test_primary_currency_follows_the_company(): void
    {
        $this->db->exec("UPDATE companies SET default_currency = 'PLN' WHERE id = ?", [$this->companyA]);
        $r = new ReportRepo($this->db, $this->ctx($this->companyA));
        $this->assertSame('PLN', $r->totals($this->at())['primaryCurrency']);
        $this->assertSame([], $r->totals($this->at())['open']);
        $this->assertSame([], $r->totals($this->at())['otherCurrencies']);
    }

    public function test_issued_this_month_counts_all_issued_document_kinds_by_issue_date(): void
    {
        $this->assertSame(0, $this->report->issuedInMonth($this->at()));
        $this->invoice('2026-12-20');                                  // issue_date 2026-10-01: not in November
        $this->assertSame(0, $this->report->issuedInMonth($this->at()));
        $mk = function (string $type, string $date, string $status = 'issued', int $company = 0): void {
            $this->db->exec("INSERT INTO documents (company_id, type, status, issue_date, lang, currency) VALUES (?, ?, ?, ?, 'de', 'EUR')", [$company ?: $this->companyA, $type, $status, $date]);
        };
        $mk('invoice', '2026-11-01');
        $mk('credit_note', '2026-11-30');
        $mk('offer', '2026-11-15');
        $mk('dunning', '2026-11-20');
        $mk('invoice', '2026-12-01');                                  // next month
        $mk('invoice', '2026-10-31');                                  // previous month
        $mk('invoice', '2026-11-10', 'draft');                         // drafts never
        $mk('invoice', '2026-11-10', 'issued', $this->companyB);       // other company
        $this->assertSame(4, $this->report->issuedInMonth($this->at()));
        $this->assertSame(1, $this->report->issuedInMonth($this->day('2026-12-02')));
    }
}
