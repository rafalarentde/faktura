<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Core\Db;
use App\Core\Migrator;
use App\Domain\AccessSuspended;
use App\Domain\DocumentNotFound;
use App\Domain\DocumentService;
use App\Domain\DunningLevel;
use App\Domain\NumberSequences;
use App\Domain\ValidationException;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Tenant\CompanyContext;
use PHPUnit\Framework\TestCase;
use Tests\Support\HookPdo;
use Tests\Support\RecordingStatement;

/**
 * DocumentService::createDunning / dunningContext / dunningQuote (Task 21, R33): a dunning letter is
 * issued when it is created, from the OUTSTANDING balance, with interest and fees only when asked
 * for and allowed, and all checks run before a number is drawn.
 */
final class DunningServiceTest extends TestCase
{
    private HookPdo $pdo;
    private Db $db;
    private int $now;
    private int $userId;
    private int $companyA;
    private int $companyB;
    private DocumentService $svc;
    private int $customer;
    private int $consumer;

    protected function setUp(): void
    {
        $this->now = (new \DateTimeImmutable('2026-11-18 10:00:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp();
        $this->pdo = new HookPdo('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->db = new Db($this->pdo, 'sqlite');
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->run();
        $this->db->exec('INSERT INTO users (email, password_hash) VALUES (?, ?)', ['o@example.com', 'x']);
        $this->userId = $this->db->lastId();
        $this->companyA = $this->company('Muster Service GmbH');
        $this->companyB = $this->company('Fremd AG');
        $this->svc = $this->svc($this->companyA);
        $this->customer = $this->customer($this->companyA, ['name' => 'Nordlicht Werbung UG', 'email' => 'buchhaltung@nordlicht.example']);
        $this->consumer = $this->customer($this->companyA, ['name' => 'Frau K. Becker', 'is_company' => '0']);
    }

    // ---- helpers ----

    private function company(string $name, string $status = 'active', ?int $baseRateBp = 127): int
    {
        $this->db->exec(
            'INSERT INTO companies (name, address, tax_number, vat_id, iban, bic, bank, access_status, dunning_base_rate_bp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$name, "Musterweg 1\n10115 Berlin", '27/123/45678', 'DE987654321', 'DE89370400440532013000', 'COBADEFFXXX', 'Commerzbank', $status, $baseRateBp]
        );
        $id = $this->db->lastId();
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$id, $this->userId, 'owner']);
        return $id;
    }

    private function ctx(int $companyId, ?Db $db = null): CompanyContext
    {
        return CompanyContext::forUser($db ?? $this->db, $this->userId, $companyId);
    }

    private function svc(int $companyId, ?Db $db = null): DocumentService
    {
        $db ??= $this->db;
        $ctx = $this->ctx($companyId, $db);
        $clock = fn (): int => $this->now;
        return new DocumentService($db, $ctx, new NumberSequences($db, $ctx, $clock), new DocumentRepo($db, $ctx), new CustomerRepo($db, $ctx), $clock);
    }

    private function customer(int $companyId, array $data = []): int
    {
        return (new CustomerRepo($this->db, $this->ctx($companyId)))->save($data + [
            'name' => 'Kunde GmbH', 'address' => "Kundenweg 1\n10115 Berlin", 'country' => 'DE', 'is_company' => '1', 'payment_days' => '14',
            'default_lang' => 'de', 'default_currency' => 'EUR',
        ]);
    }

    /** Issued invoice: net 1.200,00 + 19 % = 1.428,00 €, issued 14.10.2026, due 28.10.2026 (unless overridden). */
    private function invoice(array $extra = [], ?DocumentService $svc = null, ?int $customer = null, int $net = 120000): int
    {
        $svc ??= $this->svc;
        $id = $svc->createDraft('invoice', $extra + [
            'customer_id' => $customer ?? $this->customer, 'issue_date' => '2026-10-14', 'due_date' => '2026-10-28',
            'lines' => [['name' => 'Kampagne', 'unit' => 'flat', 'qty_milli' => 1000, 'unit_minor' => $net, 'discount_bp' => 0, 'vat_bp' => 1900]],
        ]);
        $svc->issue($id, new \DateTimeImmutable('@' . $this->now));
        return $id;
    }

    /** @return array<string, mixed> */
    private function doc(int $id): array
    {
        return (new DocumentRepo($this->db, $this->ctx($this->companyA)))->find($id) ?? $this->fail('document missing');
    }

    private function dunningRows(): int
    {
        return (int) $this->db->one("SELECT COUNT(*) AS c FROM documents WHERE type = 'dunning'")['c'];
    }

    private function counter(int $companyId = 0): int
    {
        return (new NumberSequences($this->db, $this->ctx($companyId ?: $this->companyA)))->lastCounter('dunning', new \DateTimeImmutable('@' . $this->now));
    }

    /** @return array<string, string> */
    private function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors;
        }
        $this->fail('Expected ValidationException');
    }

    /** @return array<string, mixed> the stored dunning columns */
    private function stored(int $id): array
    {
        $d = $this->doc($id);
        return array_intersect_key($d, array_flip(['dunning_level', 'dunning_outstanding_minor', 'dunning_interest_minor', 'dunning_interest_days',
            'dunning_interest_rate_bp', 'dunning_flat_fee_minor', 'dunning_fee_minor', 'dunning_claim_minor', 'dunning_pay_by']));
    }

    /** Asserts that nothing was written: no dunning row, no counter, no audit row. */
    private function assertNothingWritten(string $msg = ''): void
    {
        $this->assertSame(0, $this->dunningRows(), $msg);
        $this->assertSame(0, $this->counter(), $msg);
        $this->assertSame([], $this->db->all("SELECT 1 FROM audit_log WHERE action = 'document.dunning_create'"), $msg);
    }

    // ---- worked example (R33 test list) ----

    public function test_worked_example_exact_amounts_with_both_toggles(): void
    {
        $inv = $this->invoice();
        $quote = $this->svc->dunningQuote($inv, DunningLevel::First, true, true, false);
        $id = $this->svc->createDunning($inv, DunningLevel::First, true, true, false);
        $expected = [
            'dunning_level' => 1, 'dunning_outstanding_minor' => 142800, 'dunning_interest_minor' => 844, 'dunning_interest_days' => 21,
            'dunning_interest_rate_bp' => 1027, 'dunning_flat_fee_minor' => 4000, 'dunning_fee_minor' => null, 'dunning_claim_minor' => 147644,
            'dunning_pay_by' => '2026-11-25',
        ];
        $this->assertSame($expected, $this->stored($id));
        $this->assertSame(
            [142800, 844, 21, 1027, 4000, null, 147644, '2026-11-25', '2026-11-18'],
            [$quote['outstanding_minor'], $quote['interest_minor'], $quote['interest_days'], $quote['interest_rate_bp'], $quote['flat_fee_minor'],
                $quote['fee_minor'], $quote['claim_minor'], $quote['pay_by'], $quote['issue_date']],
            'the preview equals what is stored'
        );
        $d = $this->doc($id);
        $invoice = $this->doc($inv);
        $this->assertSame(['dunning', 'issued', 'MA-2611-001', $inv, 'EUR', 'de', '2026-11-18', 0, 0, 0, null],
            [$d['type'], $d['status'], $d['number'], $d['related_document_id'], $d['currency'], $d['lang'], $d['issue_date'], $d['net_minor'], $d['vat_minor'], $d['gross_minor'], $d['due_date']]);
        $this->assertSame($invoice['buyer'], $d['buyer'], 'buyer snapshot copied from the invoice');
        $this->assertSame($invoice['buyer_name'], $d['buyer_name']);
        $this->assertSame($invoice['customer_id'], $d['customer_id']);
        $this->assertSame('Muster Service GmbH', $d['seller']['name']);
        $this->assertSame('DE89370400440532013000', $d['seller']['iban']);
        $this->assertSame([], $d['lines']);
        $this->assertNotNull($d['issued_at']);
        // the invoice itself is untouched
        $this->assertSame($invoice, $this->doc($inv));
    }

    public function test_partial_payment_interest_uses_remaining_balance(): void
    {
        $inv = $this->invoice();
        $this->svc->addPayment($inv, 100000, new \DateTimeImmutable('2026-11-02 12:00', new \DateTimeZone('Europe/Berlin')));
        $id = $this->svc->createDunning($inv, DunningLevel::First, true, false, false);
        $s = $this->stored($id);
        $this->assertSame(42800, $s['dunning_outstanding_minor'], 'outstanding = gross - payments');
        // 428,00 x 10,27 % x 21 / 365 = 2,5289... -> 2,53 € (never computed from the 1.428,00 gross)
        $this->assertSame(253, $s['dunning_interest_minor']);
        $this->assertSame(43053, $s['dunning_claim_minor']);
        // an issued credit note reduces the balance as well
        $inv2 = $this->invoice();
        $cn = $this->svc->createCorrection($inv2);
        $this->svc->updateDraft($cn, ['issue_date' => '2026-11-01', 'lines' => [['name' => 'K', 'unit' => 'flat', 'qty_milli' => 1000, 'unit_minor' => 20000, 'discount_bp' => 0, 'vat_bp' => 1900]]]);
        $this->svc->issue($cn, new \DateTimeImmutable('@' . $this->now));
        $draftCn = $this->svc->createCorrection($inv2); // a draft credit note never counts
        $this->assertSame((142800 - 23800), $this->svc->dunningQuote($inv2, DunningLevel::Reminder, false, false, false)['outstanding_minor']);
        $this->assertGreaterThan(0, $draftCn);
    }

    public function test_interest_and_flat_fee_off_by_default(): void
    {
        $inv = $this->invoice();
        $id = $this->svc->createDunning($inv, DunningLevel::Reminder, false, false, false);
        $this->assertSame([
            'dunning_level' => 0, 'dunning_outstanding_minor' => 142800, 'dunning_interest_minor' => null, 'dunning_interest_days' => null,
            'dunning_interest_rate_bp' => null, 'dunning_flat_fee_minor' => null, 'dunning_fee_minor' => null, 'dunning_claim_minor' => 142800,
            'dunning_pay_by' => '2026-11-25',
        ], $this->stored($id));
        $audit = $this->db->all("SELECT detail FROM audit_log WHERE action = 'document.dunning_create'");
        $this->assertCount(1, $audit);
        $this->assertSame(['document_id' => $id, 'invoice_id' => $inv, 'level' => 0, 'interest' => false, 'flat_fee' => false, 'fee' => false],
            json_decode((string) $audit[0]['detail'], true), 'ids and toggles only, no amounts or names');
    }

    public function test_flat_fee_unavailable_for_private_customer(): void
    {
        $inv = $this->invoice([], null, $this->consumer);
        $ctx = $this->svc->dunningContext($inv);
        $this->assertFalse($ctx['flat_fee_allowed']);
        $this->assertFalse($ctx['is_company']);
        $this->assertSame(['flat_fee' => 'dunning.err_flat_fee_not_allowed'], $this->errors(fn () => $this->svc->createDunning($inv, DunningLevel::First, false, true, false)));
        $this->assertNothingWritten();
        // B2C surcharge: base 1,27 + 5 pp = 6,27 %; 1.428,00 x 6,27 % x 21 / 365 = 5,1512 -> 5,15 €
        $id = $this->svc->createDunning($inv, DunningLevel::First, true, false, false);
        $this->assertSame([627, 515, null], [$this->stored($id)['dunning_interest_rate_bp'], $this->stored($id)['dunning_interest_minor'], $this->stored($id)['dunning_flat_fee_minor']]);
    }

    public function test_interest_disabled_without_base_rate(): void
    {
        $this->db->exec('UPDATE companies SET dunning_base_rate_bp = NULL WHERE id = ?', [$this->companyA]);
        $inv = $this->invoice();
        $this->assertFalse($this->svc->dunningContext($inv)['base_rate_set']);
        $this->assertSame(['interest' => 'dunning.err_no_base_rate'], $this->errors(fn () => $this->svc->createDunning($inv, DunningLevel::First, true, false, false)));
        $this->assertSame(['interest' => 'dunning.err_no_base_rate'], $this->errors(fn () => $this->svc->dunningQuote($inv, DunningLevel::First, true, false, false)));
        $this->assertNothingWritten();
        $id = $this->svc->createDunning($inv, DunningLevel::First, false, true, false);
        $this->assertSame([null, 4000, 146800], [$this->stored($id)['dunning_interest_minor'], $this->stored($id)['dunning_flat_fee_minor'], $this->stored($id)['dunning_claim_minor']]);
    }

    public function test_foreign_currency_invoice_dunning_keeps_currency(): void
    {
        // Documented behaviour: interest is a percentage of the claim, so it is allowed in PLN when a
        // base rate is set; the flat fee and the dunning fee are EUR amounts and are refused for PLN.
        $this->db->exec('UPDATE companies SET dunning_fee_minor = 500 WHERE id = ?', [$this->companyA]);
        $pl = $this->customer($this->companyA, ['name' => 'Kowalski Sp. z o.o.', 'country' => 'PL', 'default_currency' => 'PLN', 'default_lang' => 'pl']);
        $inv = $this->invoice(['eur_rate' => '4.2650'], null, $pl);
        $ctx = $this->svc->dunningContext($inv);
        $this->assertSame(['PLN', false, false], [$ctx['currency'], $ctx['flat_fee_allowed'], $ctx['fee_allowed']]);
        $this->assertSame(['flat_fee' => 'dunning.err_flat_fee_not_allowed'], $this->errors(fn () => $this->svc->createDunning($inv, DunningLevel::First, false, true, false)));
        $this->assertSame(['fee' => 'dunning.err_fee_not_allowed'], $this->errors(fn () => $this->svc->createDunning($inv, DunningLevel::First, false, false, true)));
        $this->assertNothingWritten();
        $id = $this->svc->createDunning($inv, DunningLevel::First, true, false, false);
        $d = $this->doc($id);
        $this->assertSame(['PLN', 'pl', 142800, 844, 143644, null, 0], [$d['currency'], $d['lang'], $d['dunning_outstanding_minor'], $d['dunning_interest_minor'],
            $d['dunning_claim_minor'], $d['vat_eur_minor'], $d['gross_minor']], 'no VAT-in-EUR value on a dunning letter');
    }

    public function test_dunning_fee_only_when_configured_and_in_eur(): void
    {
        $inv = $this->invoice();
        $this->assertFalse($this->svc->dunningContext($inv)['fee_allowed']);
        $this->assertSame(['fee' => 'dunning.err_fee_not_allowed'], $this->errors(fn () => $this->svc->createDunning($inv, DunningLevel::First, false, false, true)));
        $this->assertNothingWritten();
        $this->db->exec('UPDATE companies SET dunning_fee_minor = 500 WHERE id = ?', [$this->companyA]);
        $id = $this->svc->createDunning($inv, DunningLevel::First, true, true, true);
        $this->assertSame([844, 4000, 500, 148144], [$this->stored($id)['dunning_interest_minor'], $this->stored($id)['dunning_flat_fee_minor'],
            $this->stored($id)['dunning_fee_minor'], $this->stored($id)['dunning_claim_minor']]);
    }

    public function test_dunning_gets_own_number_from_dunning_sequence(): void
    {
        $inv = $this->invoice();
        $this->assertSame('RE-2026-001', $this->doc($inv)['number']);
        $a = $this->svc->createDunning($inv, DunningLevel::Reminder, false, false, false);
        $b = $this->svc->createDunning($inv, DunningLevel::First, false, false, false);
        $this->assertSame(['MA-2611-001', 'MA-2611-002'], [$this->doc($a)['number'], $this->doc($b)['number']]);
        $inv2 = $this->invoice();
        $this->assertSame('RE-2026-002', $this->doc($inv2)['number'], 'the invoice sequence is independent');
        $this->assertSame(2, $this->counter());
        // another company's sequence is independent too
        $svcB = $this->svc($this->companyB);
        $invB = $this->invoice([], $svcB, $this->customer($this->companyB));
        $this->assertSame('MA-2611-001', (new DocumentRepo($this->db, $this->ctx($this->companyB)))->find($svcB->createDunning($invB, DunningLevel::Final, false, false, false))['number']);
    }

    public function test_level_must_be_strictly_higher_than_the_last_issued_one(): void
    {
        $inv = $this->invoice();
        $ctx = $this->svc->dunningContext($inv);
        $this->assertSame([DunningLevel::Reminder, DunningLevel::First, DunningLevel::Second, DunningLevel::Final], $ctx['levels']);
        $this->assertSame(DunningLevel::First, $ctx['suggested'], '21 days overdue with thresholds 7/21/35/49');
        $this->assertNull($ctx['last_level']);
        $this->svc->createDunning($inv, DunningLevel::First, false, false, false);
        foreach ([DunningLevel::Reminder, DunningLevel::First] as $level) {
            $this->assertSame(['level' => 'dunning.err_level_not_higher'], $this->errors(fn () => $this->svc->createDunning($inv, $level, false, false, false)), $level->name);
        }
        $ctx = $this->svc->dunningContext($inv);
        $this->assertSame([DunningLevel::Second, DunningLevel::Final], $ctx['levels']);
        $this->assertSame([DunningLevel::First, '2026-11-18'], [$ctx['last_level'], $ctx['last_date']]);
        $this->assertNull($ctx['suggested'], 'nothing higher is due yet');
        $this->svc->createDunning($inv, DunningLevel::Second, false, false, false);
        $this->svc->createDunning($inv, DunningLevel::Final, false, false, false);
        $this->assertSame(['level' => 'dunning.err_level_not_higher'], $this->errors(fn () => $this->svc->createDunning($inv, DunningLevel::Final, false, false, false)));
        $this->assertSame(['invoice' => 'dunning.err_final_reached'], $this->errors(fn () => $this->svc->dunningContext($inv)));
        $levels = array_column($this->db->all("SELECT dunning_level FROM documents WHERE type = 'dunning' ORDER BY id"), 'dunning_level');
        $this->assertSame([1, 2, 3], array_map('intval', $levels));
        $this->assertSame(3, $this->counter());
        // a skipped level is fine: a second invoice gets the final reminder straight away
        $inv2 = $this->invoice();
        $this->assertSame(3, $this->doc($this->svc->createDunning($inv2, DunningLevel::Final, false, false, false))['dunning_level']);
        // a draft dunning row of another kind never counts as "issued"
        $inv3 = $this->invoice();
        $this->db->exec("INSERT INTO documents (company_id, type, status, related_document_id, dunning_level, lang, currency) VALUES (?, 'dunning', 'draft', ?, 3, 'de', 'EUR')", [$this->companyA, $inv3]);
        $this->assertNull($this->svc->dunningContext($inv3)['last_level']);
    }

    public function test_ineligible_documents_are_refused_without_consuming_a_number(): void
    {
        $dueToday = $this->invoice(['issue_date' => '2026-11-04', 'due_date' => '2026-11-18']);
        $dueTomorrow = $this->invoice(['issue_date' => '2026-11-04', 'due_date' => '2026-11-19']);
        $paid = $this->invoice();
        $this->svc->addPayment($paid, 142800, new \DateTimeImmutable('2026-11-10 12:00', new \DateTimeZone('Europe/Berlin')));
        $credited = $this->invoice();
        $cn = $this->svc->createCorrection($credited);
        $this->svc->issue($cn, new \DateTimeImmutable('@' . $this->now));
        $draft = $this->svc->createDraft('invoice', ['customer_id' => $this->customer, 'due_date' => '2026-10-01', 'issue_date' => '2026-09-01',
            'lines' => [['name' => 'x', 'unit' => 'flat', 'qty_milli' => 1000, 'unit_minor' => 100, 'discount_bp' => 0, 'vat_bp' => 1900]]]);
        $offer = $this->svc->createDraft('offer', ['customer_id' => $this->customer, 'issue_date' => '2026-09-01',
            'lines' => [['name' => 'x', 'unit' => 'flat', 'qty_milli' => 1000, 'unit_minor' => 100, 'discount_bp' => 0, 'vat_bp' => 1900]]]);
        $this->svc->issue($offer, new \DateTimeImmutable('@' . $this->now));
        $foreign = $this->invoice([], $this->svc($this->companyB), $this->customer($this->companyB));
        $before = $this->db->all('SELECT * FROM documents ORDER BY id');

        $cases = [
            'due today' => [$dueToday, ['invoice' => 'dunning.err_not_overdue']],
            'due tomorrow' => [$dueTomorrow, ['invoice' => 'dunning.err_not_overdue']],
            'paid' => [$paid, ['invoice' => 'dunning.err_paid']],
            'credited' => [$credited, ['invoice' => 'dunning.err_credited']],
            'draft invoice' => [$draft, ['invoice' => 'dunning.err_not_issued']],
        ];
        foreach ($cases as $name => [$id, $errors]) {
            $this->assertSame($errors, $this->errors(fn () => $this->svc->createDunning($id, DunningLevel::First, true, true, false)), $name);
            $this->assertSame($errors, $this->errors(fn () => $this->svc->dunningContext($id)), $name);
        }
        foreach (['offer' => $offer, 'credit note' => $cn, 'foreign' => $foreign, 'unknown' => 999999] as $name => $id) {
            foreach ([fn () => $this->svc->createDunning($id, DunningLevel::First, false, false, false), fn () => $this->svc->dunningContext($id),
                fn () => $this->svc->dunningQuote($id, DunningLevel::First, false, false, false)] as $call) {
                try {
                    $call();
                    $this->fail("$name accepted");
                } catch (DocumentNotFound $e) {
                    $this->assertSame((new DocumentNotFound())->getMessage(), $e->getMessage(), "$name: identical to a missing document");
                }
            }
        }
        // a dunning letter id is not an invoice either
        $ok = $this->svc->createDunning($this->invoice(), DunningLevel::First, false, false, false);
        $this->expectException(DocumentNotFound::class);
        try {
            $this->svc->createDunning($ok, DunningLevel::Final, false, false, false);
        } finally {
            $this->assertSame(1, $this->dunningRows());
            $this->assertSame(1, $this->counter());
            $this->assertSame(0, $this->counter($this->companyB));
            $this->assertCount(count($before) + 2, $this->db->all('SELECT id FROM documents'));
        }
    }

    public function test_suspended_company_cannot_create_and_consumes_no_number(): void
    {
        $inv = $this->invoice();
        $this->db->exec("UPDATE companies SET access_status = 'suspended' WHERE id = ?", [$this->companyA]);
        $svc = $this->svc($this->companyA);
        $ctx = $svc->dunningContext($inv);
        $this->assertFalse($ctx['can_issue'], 'the preview still works and says so');
        $this->assertSame(142800, $svc->dunningQuote($inv, DunningLevel::First, false, false, false)['claim_minor']);
        $this->expectException(AccessSuspended::class);
        try {
            $svc->createDunning($inv, DunningLevel::First, false, false, false);
        } finally {
            $this->assertNothingWritten();
        }
    }

    public function test_an_out_of_bounds_stored_rate_is_a_validation_error_not_a_crash(): void
    {
        // T20: the settings bounds (-10..30 %) now refuse what used to overflow the interest arithmetic (the
        // overflow guards themselves stay covered in DunningTest); a hand-edited row is a refusal, not a 500.
        $this->db->exec('UPDATE companies SET dunning_base_rate_bp = ? WHERE id = ?', [9_000_000_000_000, $this->companyA]);
        $inv = $this->invoice();
        $this->assertSame(['invoice' => 'dunning.err_settings'], $this->errors(fn () => $this->svc->createDunning($inv, DunningLevel::First, true, false, false)));
        $this->assertSame(['invoice' => 'dunning.err_settings'], $this->errors(fn () => $this->svc->dunningQuote($inv, DunningLevel::First, true, false, false)));
        $this->assertNothingWritten();
    }

    public function test_failure_after_next_rolls_back_without_gap(): void
    {
        $inv = $this->invoice();
        $this->pdo->onPrepare = function (string $sql): void {
            if (str_starts_with($sql, 'UPDATE `documents`') && str_contains($sql, '`dunning_claim_minor`')) {
                throw new \RuntimeException('disk full');
            }
        };
        try {
            $this->svc->createDunning($inv, DunningLevel::First, true, true, false);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        } finally {
            $this->pdo->onPrepare = null;
        }
        $this->assertNothingWritten('rolled back: no row, no counter, no audit');
        $this->assertSame('MA-2611-001', $this->doc($this->svc->createDunning($inv, DunningLevel::First, true, true, false))['number'], 'no gap');
    }

    public function test_number_collision_retry(): void
    {
        $inv = $this->invoice();
        $this->db->exec("INSERT INTO documents (company_id, type, status, number, lang, currency) VALUES (?, 'dunning', 'issued', 'MA-2611-001', 'de', 'EUR')", [$this->companyA]);
        $armed = true;
        $this->pdo->onPrepare = function (string $sql, HookPdo $pdo) use (&$armed): void {
            if ($armed && str_starts_with($sql, 'UPDATE `documents`') && str_contains($sql, '`dunning_claim_minor`')) {
                $armed = false; // a concurrent dunning took MA-2611-002 after our check
                $pdo->exec("INSERT INTO documents (company_id, type, status, number, lang, currency) VALUES ({$this->companyA}, 'dunning', 'issued', 'MA-2611-002', 'de', 'EUR')");
            }
        };
        $id = $this->svc->createDunning($inv, DunningLevel::First, false, false, false);
        $this->pdo->onPrepare = null;
        $this->assertSame('MA-2611-003', $this->doc($id)['number']);
        $this->assertSame(1, $this->doc($id)['dunning_level']);
    }

    public function test_mysql_first_statement_in_the_transaction_locks_the_invoice(): void
    {
        $inv = $this->invoice();
        $db = new Db($this->pdo, 'mysql');
        $svc = $this->svc($this->companyA, $db);
        $this->pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [RecordingStatement::class]);
        $this->pdo->onPrepare = fn (string $sql): string => str_ends_with($sql, ' FOR UPDATE') ? substr($sql, 0, -strlen(' FOR UPDATE')) . ' /* FOR UPDATE */' : $sql;
        $this->pdo->onBegin = function (): void {
            RecordingStatement::$log[] = ['BEGIN', []];
        };
        RecordingStatement::$log = [];
        try {
            $id = $svc->createDunning($inv, DunningLevel::First, true, true, false);
        } finally {
            $this->pdo->onBegin = null;
            $this->pdo->onPrepare = null;
        }
        $log = RecordingStatement::$log;
        $begin = array_search('BEGIN', array_column($log, 0), true);
        $this->assertSame(0, $begin, 'nothing is read before the transaction');
        $after = array_slice($log, 1);
        $this->assertStringContainsString('FROM `documents`', $after[0][0]);
        $this->assertStringContainsString('FOR UPDATE', $after[0][0], 'the first statement is the locking read of the invoice');
        $this->assertSame($inv, (int) $after[0][1][1]);
        $pos = fn (callable $match): int => (int) array_key_first(array_filter($after, fn (array $e): bool => $match($e[0])));
        $insert = $pos(fn (string $s): bool => str_starts_with($s, 'INSERT INTO `documents`'));
        $format = $pos(fn (string $s): bool => str_contains($s, 'FROM `number_formats`') && str_contains($s, 'FOR UPDATE'));
        $this->assertGreaterThan(0, $insert);
        $this->assertGreaterThan($insert, $format, 'invoice lock, then the new document row, then NumberSequences::next');
        foreach (array_slice($after, 1, $insert - 1) as [$sql]) {
            if (str_contains($sql, 'FROM `payments`') || (str_contains($sql, 'FROM `documents`') && str_contains($sql, 'related_document_id'))) {
                $this->assertStringContainsString('FOR UPDATE', $sql, 'scans of the invoice are locking reads');
            }
        }
        $this->assertGreaterThan(0, $id);
    }

    public function test_preview_values_never_trusted_from_outside_and_toggles_off_by_default(): void
    {
        // the quote is recomputed from the database: a payment between preview and creation counts
        $inv = $this->invoice();
        $before = $this->svc->dunningQuote($inv, DunningLevel::First, true, false, false);
        $this->svc->addPayment($inv, 100000, new \DateTimeImmutable('2026-11-17 12:00', new \DateTimeZone('Europe/Berlin')));
        $id = $this->svc->createDunning($inv, DunningLevel::First, true, false, false);
        $this->assertSame(844, $before['interest_minor']);
        $this->assertSame([42800, 253], [$this->stored($id)['dunning_outstanding_minor'], $this->stored($id)['dunning_interest_minor']]);
    }
}
