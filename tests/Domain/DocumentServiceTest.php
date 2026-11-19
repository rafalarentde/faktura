<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Core\Db;
use App\Core\Migrator;
use App\Domain\AccessSuspended;
use App\Domain\DocumentLocked;
use App\Domain\DocumentNotFound;
use App\Domain\DocumentService;
use App\Domain\NumberSequences;
use App\Domain\Totals;
use App\Domain\ValidationException;
use App\Domain\VatNeedsConfirmation;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Repo\ItemRepo;
use App\Tenant\CompanyContext;
use App\Vies\ViesStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HookPdo;

final class DocumentServiceTest extends TestCase
{
    private const NOW = 1_795_514_400; // 2026-11-24 10:00:00 UTC

    private HookPdo $pdo;
    private Db $db;
    private int $userId;
    private int $companyA;
    private int $companyB;
    private DocumentService $svc;
    private int $customer;

    protected function setUp(): void
    {
        $this->pdo = new HookPdo('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->db = new Db($this->pdo, 'sqlite');
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->run();
        $this->db->exec('INSERT INTO users (email, password_hash) VALUES (?, ?)', ['o@example.com', 'x']);
        $this->userId = $this->db->lastId();
        $this->companyA = $this->company('A GmbH');
        $this->companyB = $this->company('B GmbH');
        $this->svc = $this->svc($this->companyA);
        $this->customer = $this->customer($this->companyA);
    }

    // ---- helpers ----

    private function company(string $name, string $status = 'active', ?string $until = null): int
    {
        $this->db->exec(
            'INSERT INTO companies (name, address, tax_number, vat_id, iban, bic, bank, intro_text, outro_text, default_payment_days, access_status, access_until)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$name, "Hauptstr. 1\n10115 Berlin", '27/123/45678', 'DE123456789', 'DE89370400440532013000', 'COBADEFFXXX', 'Bank',
                'Hallo, hier deine Rechnung.', 'Danke!', 14, $status, $until]
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
        return new DocumentService(
            $this->db, $ctx, new NumberSequences($this->db, $ctx, $clock), new DocumentRepo($this->db, $ctx),
            new CustomerRepo($this->db, $ctx), $clock
        );
    }

    private function repo(int $companyId): DocumentRepo
    {
        return new DocumentRepo($this->db, $this->ctx($companyId));
    }

    private function customers(int $companyId): CustomerRepo
    {
        return new CustomerRepo($this->db, $this->ctx($companyId));
    }

    /** @param array<string, mixed> $data */
    private function customer(int $companyId, array $data = []): int
    {
        return $this->customers($companyId)->save($data + [
            'name' => 'Kunde GmbH', 'country' => 'DE', 'is_company' => '1', 'payment_days' => '30',
            'default_lang' => 'de', 'default_currency' => 'EUR',
        ]);
    }

    private function euCustomer(?ViesStatus $vies, string $currency = 'EUR'): int
    {
        $id = $this->customer($this->companyA, [
            'name' => 'Société SA', 'country' => 'FR', 'vat_id' => 'FR40303265045', 'default_currency' => $currency,
        ]);
        if ($vies !== null) {
            $this->customers($this->companyA)->recordVies($id, $vies, new \DateTimeImmutable('@' . self::NOW));
        }
        return $id;
    }

    /** @return array<string, mixed> */
    private function line(array $o = []): array
    {
        return $o + ['name' => 'Beratung', 'unit' => 'hour', 'qty_milli' => 1000, 'unit_minor' => 10000, 'discount_bp' => 0, 'vat_bp' => 1900];
    }

    /** @param array<string, mixed> $extra */
    private function draft(array $extra = [], ?DocumentService $svc = null, string $type = 'invoice'): int
    {
        return ($svc ?? $this->svc)->createDraft($type, $extra + ['customer_id' => $this->customer, 'lines' => [$this->line()]]);
    }

    private function at(string $utc = '2026-11-24 10:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    private function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date . ' 12:00:00', new \DateTimeZone('Europe/Berlin'));
    }

    /** @return array<string, mixed> */
    private function doc(int $id): array
    {
        return $this->repo($this->companyA)->find($id) ?? $this->fail('document missing');
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

    /** @return list<array<string, mixed>> */
    private function audit(string $action): array
    {
        return $this->db->all('SELECT * FROM audit_log WHERE action = ? ORDER BY id', [$action]);
    }

    private function counter(string $type, string $date = '2026-11-24'): int
    {
        return (new NumberSequences($this->db, $this->ctx($this->companyA)))->lastCounter($type, $this->day($date));
    }

    /** Stored totals must equal a recomputation from the stored lines (with the document's sign). */
    private function assertConsistent(array $doc): void
    {
        $t = $this->svc->totalsFor($doc);
        $sign = $doc['type'] === 'credit_note' ? -1 : 1;
        $this->assertSame($sign * $t->netMinor, $doc['net_minor']);
        $this->assertSame($sign * $t->vatMinor, $doc['vat_minor']);
        $this->assertSame($sign * $t->grossMinor, $doc['gross_minor']);
        $this->assertSame($t->vatEurMinor === null ? null : $sign * $t->vatEurMinor, $doc['vat_eur_minor']);
        foreach ($doc['lines'] as $l) {
            $this->assertSame(Totals::lineNet(new \App\Domain\Line($l['qty_milli'], $l['unit_minor'], $l['discount_bp'], $l['vat_bp'])), $l['net_minor']);
        }
    }

    private function armThrowOnIssueUpdate(): void
    {
        $this->pdo->onPrepare = function (string $sql): void {
            if (str_starts_with($sql, 'UPDATE `documents`') && str_contains($sql, '`status`')) {
                throw new \RuntimeException('disk full');
            }
        };
    }

    // ---- brief tests ----

    public function test_issue_assigns_number_and_locks(): void
    {
        $id = $this->draft();
        $this->assertNull($this->doc($id)['number']);
        $this->assertSame('draft', $this->doc($id)['status']);

        $this->assertSame('RE-2026-001', $this->svc->issue($id, $this->at()));
        $doc = $this->doc($id);
        $this->assertSame('issued', $doc['status']);
        $this->assertSame('RE-2026-001', $doc['number']);
        $this->assertSame('2026-11-24', $doc['issue_date']);
        $this->assertSame('2026-11-24 10:00:00', $doc['issued_at']);
        $this->assertSame(10000, $doc['net_minor']);
        $this->assertSame(1900, $doc['vat_minor']);
        $this->assertSame(11900, $doc['gross_minor']);
        $this->assertSame('A GmbH', $doc['seller']['name']);
        $this->assertSame('Kunde GmbH', $doc['buyer']['name']);
        $this->assertConsistent($doc);

        $this->expectException(DocumentLocked::class);
        $this->svc->updateDraft($id, ['notes' => 'x']);
    }

    public function test_issuing_twice_is_refused(): void
    {
        $id = $this->draft();
        $this->svc->issue($id, $this->at());
        $this->expectException(DocumentLocked::class);
        $this->svc->issue($id, $this->at());
    }

    public function test_issued_document_cannot_be_deleted(): void
    {
        $id = $this->draft();
        $this->svc->issue($id, $this->at());
        $before = $this->doc($id);
        try {
            $this->svc->deleteDraft($id);
            $this->fail('Expected DocumentLocked');
        } catch (DocumentLocked) {
        }
        $this->assertSame($before, $this->doc($id));
        $this->assertCount(1, $this->db->all('SELECT id FROM document_lines WHERE document_id = ?', [$id]));
    }

    public function test_draft_has_no_number_and_deleting_it_leaves_no_gap(): void
    {
        $a = $this->draft();
        $this->assertSame('RE-2026-001', $this->svc->issue($a, $this->at()));
        $b = $this->draft();
        $this->assertNull($this->doc($b)['number']);
        $this->svc->deleteDraft($b);
        $this->assertNull($this->repo($this->companyA)->find($b));
        $this->assertSame([], $this->db->all('SELECT id FROM document_lines WHERE document_id = ?', [$b]));
        $c = $this->draft();
        $this->assertSame('RE-2026-002', $this->svc->issue($c, $this->at()));
    }

    public function test_snapshot_survives_customer_edit(): void
    {
        $id = $this->draft();
        $number = $this->svc->issue($id, $this->at());
        $before = $this->doc($id);

        $this->customers($this->companyA)->save(['name' => 'Neuer Name AG', 'vat_id' => 'DE999999999', 'address' => 'Anderswo 5'], $this->customer);
        $this->db->exec('UPDATE companies SET name = ?, iban = ? WHERE id = ?', ['A Neu GmbH', 'DE02120300000000202051', $this->companyA]);

        $after = $this->doc($id);
        $this->assertSame($number, $after['number']);
        $this->assertSame('Kunde GmbH', $after['buyer']['name']);
        $this->assertNull($after['buyer']['vat_id']);
        $this->assertSame('A GmbH', $after['seller']['name']);
        $this->assertSame($before, $after);
    }

    public function test_partial_payment_status_and_balance(): void
    {
        $id = $this->draft(['issue_date' => '2026-11-24', 'due_date' => '2026-12-10']);
        $this->svc->issue($id, $this->at());
        $this->assertSame(11900, $this->svc->balanceMinor($id));
        $this->assertSame('open', $this->svc->paymentStatus($id, $this->day('2026-11-25')));

        $this->svc->addPayment($id, 5000, $this->day('2026-11-25'), 'Teilzahlung');
        $this->assertSame(6900, $this->svc->balanceMinor($id));
        $this->assertSame('partial', $this->svc->paymentStatus($id, $this->day('2026-11-25')));
        $this->assertSame('partial', $this->svc->paymentStatus($id, $this->day('2026-12-10')), 'due today is not overdue');
        $this->assertSame('overdue', $this->svc->paymentStatus($id, $this->day('2026-12-11')), 'overdue wins over partial');
        $this->assertSame([
            'status' => 'partial', 'gross_minor' => 11900, 'paid_minor' => 5000, 'credited_minor' => 0,
            'balance_minor' => 6900, 'due_date' => '2026-12-10',
        ], $this->svc->paymentSummary($id, $this->day('2026-11-30')));

        $this->svc->addPayment($id, 6900, $this->day('2026-12-20'));
        $this->assertSame(0, $this->svc->balanceMinor($id));
        $this->assertSame('paid', $this->svc->paymentStatus($id, $this->day('2027-01-30')));
    }

    public function test_draft_status_and_open_unpaid_invoice(): void
    {
        $id = $this->draft(['due_date' => null]);
        $this->assertSame('draft', $this->svc->paymentStatus($id, $this->day('2026-11-25')));
        $this->svc->issue($id, $this->at());
        // due date defaulted to issue + 30 customer days
        $this->assertSame('2026-12-24', $this->doc($id)['due_date']);
        $this->assertSame('open', $this->svc->paymentStatus($id, $this->day('2026-12-24')));
        $this->assertSame('overdue', $this->svc->paymentStatus($id, $this->day('2026-12-25')));
    }

    public function test_overpayment_rejected(): void
    {
        $id = $this->draft();
        $this->svc->issue($id, $this->at());
        $this->assertSame(['amount' => 'document.err_overpayment'], $this->errors(fn () => $this->svc->addPayment($id, 11901, $this->day('2026-11-25'))));
        $this->svc->addPayment($id, 11900, $this->day('2026-11-25'));
        $this->assertSame(['amount' => 'document.err_overpayment'], $this->errors(fn () => $this->svc->addPayment($id, 1, $this->day('2026-11-25'))));
        $this->assertSame(['amount' => 'document.err_amount'], $this->errors(fn () => $this->svc->addPayment($id, 0, $this->day('2026-11-25'))));
        $this->assertCount(1, $this->db->all('SELECT id FROM payments'));
    }

    public function test_payment_validation(): void
    {
        $id = $this->draft(['issue_date' => '2026-11-20']);
        $this->svc->issue($id, $this->at());
        $this->assertSame(['amount' => 'document.err_amount'], $this->errors(fn () => $this->svc->addPayment($id, -5, $this->day('2026-11-25'))));
        $this->assertSame(['paid_on' => 'document.err_paid_on'], $this->errors(fn () => $this->svc->addPayment($id, 5, $this->day('2026-11-19'))));
        $this->assertSame(['note' => 'document.err_note'], $this->errors(fn () => $this->svc->addPayment($id, 5, $this->day('2026-11-25'), str_repeat('ä', 191))));
        $this->svc->addPayment($id, 5, $this->day('2026-11-20'));
        $row = $this->db->one('SELECT * FROM payments');
        $this->assertSame('2026-11-20', $row['paid_on']);
        $this->assertSame($this->companyA, (int) $row['company_id']);
    }

    public function test_concurrent_payment_cannot_overpay(): void
    {
        $id = $this->draft();
        $this->svc->issue($id, $this->at());
        // A concurrent request commits a payment of the full balance right before our locked read.
        $armed = true;
        $this->pdo->onPrepare = function (string $sql, HookPdo $pdo) use (&$armed, $id): void {
            if ($armed && str_starts_with($sql, 'SELECT * FROM `documents`')) {
                $armed = false;
                $pdo->exec("INSERT INTO payments (company_id, document_id, amount_minor, paid_on) VALUES ({$this->companyA}, $id, 11000, '2026-11-25')");
            }
        };
        $this->assertSame(['amount' => 'document.err_overpayment'], $this->errors(fn () => $this->svc->addPayment($id, 1000, $this->day('2026-11-25'))));
        $this->pdo->onPrepare = null;
        // (the simulated row was written on our own connection, so the rollback removed it too)
        $this->assertSame(11900, $this->svc->balanceMinor($id));
        // sequential: the second payment fails once the first filled the balance
        $this->svc->addPayment($id, 11900, $this->day('2026-11-25'));
        $this->assertSame(['amount' => 'document.err_overpayment'], $this->errors(fn () => $this->svc->addPayment($id, 1, $this->day('2026-11-25'))));
        $this->assertSame(0, $this->svc->balanceMinor($id));
    }

    public function test_payment_only_on_issued_invoices(): void
    {
        $draft = $this->draft();
        $this->assertSame(['document' => 'document.err_payment_not_allowed'], $this->errors(fn () => $this->svc->addPayment($draft, 100, $this->day('2026-11-25'))));

        $offer = $this->draft([], null, 'offer');
        $this->svc->issue($offer, $this->at());
        $this->assertSame(['document' => 'document.err_payment_not_allowed'], $this->errors(fn () => $this->svc->addPayment($offer, 100, $this->day('2026-11-25'))));

        $inv = $this->draft();
        $this->svc->issue($inv, $this->at());
        $cn = $this->svc->createCorrection($inv);
        $this->assertSame(['document' => 'document.err_payment_not_allowed'], $this->errors(fn () => $this->svc->addPayment($cn, 100, $this->day('2026-11-25'))));
        $this->svc->issue($cn, $this->at());
        $this->assertSame(['document' => 'document.err_payment_not_allowed'], $this->errors(fn () => $this->svc->addPayment($cn, 100, $this->day('2026-11-25'))));
        $this->assertSame([], $this->db->all('SELECT id FROM payments'));
    }

    public function test_correction_links_to_original_and_zeroes_it(): void
    {
        $inv = $this->draft(['lines' => [$this->line(['qty_milli' => 2000])]]); // 200.00 + 38.00
        $this->svc->issue($inv, $this->at());
        $this->assertSame(23800, $this->doc($inv)['gross_minor']);

        $cn = $this->svc->createCorrection($inv);
        $draft = $this->doc($cn);
        $this->assertSame('credit_note', $draft['type']);
        $this->assertSame('draft', $draft['status']);
        $this->assertSame($inv, $draft['related_document_id']);
        $this->assertNull($draft['number']);
        $this->assertSame(2000, $draft['lines'][0]['qty_milli'], 'lines copied as positive lines');
        $this->assertSame(10000, $draft['lines'][0]['unit_minor']);
        $this->assertSame(-23800, $draft['gross_minor'], 'credit note totals are stored negative');

        // partial correction: one of two hours
        $this->svc->updateDraft($cn, ['lines' => [$this->line(['qty_milli' => 1000])]]);
        $this->assertSame('KO-2026-001', $this->svc->issue($cn, $this->at()));
        $issued = $this->doc($cn);
        $this->assertSame(-10000, $issued['net_minor']);
        $this->assertSame(-1900, $issued['vat_minor']);
        $this->assertSame(-11900, $issued['gross_minor']);
        $this->assertSame(-1900, $issued['vat_eur_minor']);
        $this->assertSame('Kunde GmbH', $issued['buyer']['name']);
        $this->assertConsistent($issued);
        $this->assertSame(11900, $this->svc->balanceMinor($inv));
        $this->assertSame('open', $this->svc->paymentStatus($inv, $this->day('2026-11-25')));

        // a second credit note for 2 hours would exceed the gross
        $cn2 = $this->svc->createCorrection($inv);
        $this->assertSame(['lines' => 'document.err_credit_exceeds'], $this->errors(fn () => $this->svc->issue($cn2, $this->at())));
        $this->assertSame('draft', $this->doc($cn2)['status']);
        $this->assertSame(1, $this->counter('credit_note'), 'refused credit note consumed no number');

        // the rest exactly: fully credited
        $this->svc->updateDraft($cn2, ['lines' => [$this->line(['qty_milli' => 1000])]]);
        $this->assertSame('KO-2026-002', $this->svc->issue($cn2, $this->at()));
        $this->assertSame(0, $this->svc->balanceMinor($inv));
        $this->assertSame('credited', $this->svc->paymentStatus($inv, $this->day('2027-06-01')));
        $s = $this->svc->paymentSummary($inv, $this->day('2026-11-25'));
        $this->assertSame(23800, $s['credited_minor']);
        $this->assertSame(0, $s['balance_minor']);

        // a credit note cannot be corrected, nor can a fully credited invoice be credited again
        $this->assertSame(['document' => 'document.err_correction_not_allowed'], $this->errors(fn () => $this->svc->createCorrection($cn)));
        $cn3 = $this->svc->createCorrection($inv);
        $this->assertSame(['lines' => 'document.err_credit_exceeds'], $this->errors(fn () => $this->svc->issue($cn3, $this->at())));
    }

    public function test_correction_only_for_issued_invoices(): void
    {
        $draft = $this->draft();
        $this->assertSame(['document' => 'document.err_correction_not_allowed'], $this->errors(fn () => $this->svc->createCorrection($draft)));
        $offer = $this->draft([], null, 'offer');
        $this->svc->issue($offer, $this->at());
        $this->assertSame(['document' => 'document.err_correction_not_allowed'], $this->errors(fn () => $this->svc->createCorrection($offer)));
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) AS c FROM documents WHERE type = 'offer'")['c']);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) AS c FROM documents WHERE type = 'credit_note'")['c']);
    }

    public function test_correction_keeps_customer_currency_lang_and_link(): void
    {
        $inv = $this->draft();
        $this->svc->issue($inv, $this->at());
        $cn = $this->svc->createCorrection($inv);
        $other = $this->customer($this->companyA, ['name' => 'Andere GmbH']);
        foreach (['customer_id' => $other, 'currency' => 'PLN', 'lang' => 'en', 'related_document_id' => 999] as $field => $value) {
            $this->assertSame([$field => 'document.err_correction_fixed'], $this->errors(fn () => $this->svc->updateDraft($cn, [$field => $value])), $field);
        }
        // passing the unchanged values is fine
        $this->svc->updateDraft($cn, ['customer_id' => $this->customer, 'currency' => 'EUR', 'lang' => 'de', 'notes' => 'Storno']);
        $this->assertSame('Storno', $this->doc($cn)['notes']);
        $this->assertSame($inv, $this->doc($cn)['related_document_id']);
    }

    public function test_correction_follows_original_reverse_charge(): void
    {
        $fr = $this->euCustomer(ViesStatus::Valid);
        $inv = $this->draft(['customer_id' => $fr]);
        $this->svc->issue($inv, $this->at());
        // VIES later invalid: the credit note still follows the invoice
        $this->customers($this->companyA)->recordVies($fr, ViesStatus::Invalid, new \DateTimeImmutable('@' . self::NOW));
        $cn = $this->svc->createCorrection($inv);
        $this->svc->issue($cn, $this->at());
        $doc = $this->doc($cn);
        $this->assertSame(1, $doc['reverse_charge']);
        $this->assertSame('invoice.reverse_charge_note', $doc['vat_note_key']);
        $this->assertSame(0, $doc['vat_minor']);
        $this->assertSame(-10000, $doc['gross_minor']);
        $this->assertSame('credited', $this->svc->paymentStatus($inv, $this->day('2026-11-25')));
    }

    public function test_suspended_company_cannot_issue(): void
    {
        foreach ([['suspended', null], ['trial', '2026-11-23']] as [$status, $until]) {
            $cid = $this->company("S $status", $status, $until);
            $svc = $this->svc($cid);
            $cust = $this->customer($cid);
            $id = $svc->createDraft('invoice', ['customer_id' => $cust, 'lines' => [$this->line()]]); // drafts allowed
            try {
                $svc->issue($id, $this->at());
                $this->fail('Expected AccessSuspended');
            } catch (AccessSuspended) {
            }
            $doc = $this->repo($cid)->find($id);
            $this->assertSame('draft', $doc['status']);
            $this->assertNull($doc['number']);
            $this->assertNull($doc['buyer_json']);
            $this->assertSame(0, (new NumberSequences($this->db, $this->ctx($cid)))->lastCounter('invoice', $this->day('2026-11-24')));
            $this->assertCount(1, $this->repo($cid)->list());
        }
        $this->assertSame([], $this->audit('document.issue'));
    }

    public function test_cross_company_document_not_visible(): void
    {
        $svcB = $this->svc($this->companyB);
        $custB = $this->customer($this->companyB, ['name' => 'B Kunde']);
        $idB = $svcB->createDraft('invoice', ['customer_id' => $custB, 'lines' => [$this->line()]]);
        $issuedB = $svcB->createDraft('invoice', ['customer_id' => $custB, 'lines' => [$this->line()]]);
        $svcB->issue($issuedB, $this->at());
        $before = $this->db->all('SELECT * FROM documents ORDER BY id');
        $linesBefore = $this->db->all('SELECT * FROM document_lines ORDER BY id');

        $repoA = $this->repo($this->companyA);
        $this->assertNull($repoA->find($idB));
        $this->assertNull($repoA->find($issuedB));
        $this->assertSame([], $repoA->list());
        $this->assertSame(0, $repoA->count());

        $calls = [
            'update' => fn () => $this->svc->updateDraft($idB, ['notes' => 'hacked']),
            'delete' => fn () => $this->svc->deleteDraft($idB),
            'issue' => fn () => $this->svc->issue($idB, $this->at()),
            'payment' => fn () => $this->svc->addPayment($issuedB, 100, $this->day('2026-11-25')),
            'correction' => fn () => $this->svc->createCorrection($issuedB),
            'status' => fn () => $this->svc->paymentStatus($issuedB, $this->day('2026-11-25')),
            'summary' => fn () => $this->svc->paymentSummary($issuedB, $this->day('2026-11-25')),
            'balance' => fn () => $this->svc->balanceMinor($issuedB),
            'unknown' => fn () => $this->svc->issue(999999, $this->at()),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("$name: expected DocumentNotFound");
            } catch (DocumentNotFound $e) {
                $this->assertSame('Document not found.', $e->getMessage());
            }
        }
        $this->assertSame($before, $this->db->all('SELECT * FROM documents ORDER BY id'));
        $this->assertSame($linesBefore, $this->db->all('SELECT * FROM document_lines ORDER BY id'));
        $this->assertSame([], $this->db->all('SELECT id FROM payments'));
    }

    public function test_foreign_customer_and_item_rejected(): void
    {
        $custB = $this->customer($this->companyB, ['name' => 'B Kunde']);
        $itemB = (new ItemRepo($this->db, $this->ctx($this->companyB)))->save(['type' => 'service', 'unit' => 'hour', 'price' => '10', 'currency' => 'EUR', 'vat_bp' => '1900', 'name_de' => 'X']);
        $itemA = (new ItemRepo($this->db, $this->ctx($this->companyA)))->save(['type' => 'service', 'unit' => 'hour', 'price' => '10', 'currency' => 'EUR', 'vat_bp' => '1900', 'name_de' => 'Y']);

        $this->assertSame(['customer_id' => 'document.err_customer'], $this->errors(fn () => $this->draft(['customer_id' => $custB])));
        $this->assertSame(['customer_id' => 'document.err_customer'], $this->errors(fn () => $this->draft(['customer_id' => null])));
        $this->assertSame(['lines.0.item_id' => 'document.err_line_item'], $this->errors(fn () => $this->draft(['lines' => [$this->line(['item_id' => $itemB])]])));
        $id = $this->draft(['lines' => [$this->line(['item_id' => $itemA])]]);
        $this->assertSame($itemA, $this->doc($id)['lines'][0]['item_id']);
        $this->assertSame(['customer_id' => 'document.err_customer'], $this->errors(fn () => $this->svc->updateDraft($id, ['customer_id' => $custB])));
        $this->assertSame(0, $this->repo($this->companyB)->count());
    }

    // ---- VAT (Review Focus 1) ----

    /** @return array<string, array{0: ?ViesStatus}> */
    public static function unconfirmedVies(): array
    {
        return ['never checked' => [null], 'invalid' => [ViesStatus::Invalid], 'unavailable' => [ViesStatus::Unavailable]];
    }

    #[DataProvider('unconfirmedVies')]
    public function test_unverified_eu_vat_id_needs_confirmation(?ViesStatus $vies): void
    {
        $fr = $this->euCustomer($vies);
        $id = $this->draft(['customer_id' => $fr]);
        $draft = $this->doc($id);
        $this->assertSame(0, $draft['reverse_charge'], 'no silent reverse charge on a draft');
        $this->assertSame('vies_not_confirmed', $draft['vat_reason']);
        $this->assertSame(1900, $draft['vat_minor']);

        try {
            $this->svc->issue($id, $this->at());
            $this->fail('Expected VatNeedsConfirmation');
        } catch (VatNeedsConfirmation $e) {
            $this->assertTrue($e->decision->needsConfirmation);
            $this->assertSame('vies_not_confirmed', $e->decision->reason);
        }
        $this->assertSame('draft', $this->doc($id)['status']);
        $this->assertSame(0, $this->counter('invoice'));

        $this->assertSame('RE-2026-001', $this->svc->issue($id, $this->at(), true));
        $doc = $this->doc($id);
        $this->assertSame(0, $doc['reverse_charge']);
        $this->assertNull($doc['vat_note_key']);
        $this->assertSame(1900, $doc['vat_minor']);
        $this->assertSame(11900, $doc['gross_minor']);
        $this->assertSame($vies?->value, $doc['buyer']['vies_status']);
        $audit = $this->audit('document.issue');
        $this->assertCount(1, $audit);
        $this->assertSame(
            ['document_id' => $id, 'type' => 'invoice', 'number' => 'RE-2026-001', 'vat_confirmed' => true],
            json_decode((string) $audit[0]['detail'], true)
        );
    }

    public function test_valid_vies_issues_reverse_charge(): void
    {
        $fr = $this->euCustomer(ViesStatus::Valid);
        $id = $this->draft(['customer_id' => $fr, 'lines' => [$this->line(), $this->line(['vat_bp' => 700, 'unit_minor' => 5000])]]);
        $this->assertSame(1, $this->doc($id)['reverse_charge']);
        $this->assertSame('reverse_charge', $this->doc($id)['vat_reason']);
        $this->svc->issue($id, $this->at());
        $doc = $this->doc($id);
        $this->assertSame(1, $doc['reverse_charge']);
        $this->assertSame('invoice.reverse_charge_note', $doc['vat_note_key']);
        $this->assertSame(0, $doc['vat_minor']);
        $this->assertSame(15000, $doc['net_minor']);
        $this->assertSame($doc['net_minor'], $doc['gross_minor']);
        $this->assertSame('FR40303265045', $doc['buyer']['vat_id']);
        $this->assertSame('valid', $doc['buyer']['vies_status']);
        $this->assertNotNull($doc['buyer']['vies_checked_at']);
        $this->assertConsistent($doc);
        $detail = json_decode((string) $this->audit('document.issue')[0]['detail'], true);
        $this->assertFalse($detail['vat_confirmed']);
    }

    public function test_issue_decides_vat_from_the_buyer_at_issue_time(): void
    {
        $fr = $this->euCustomer(ViesStatus::Valid);
        $id = $this->draft(['customer_id' => $fr]);
        $this->customers($this->companyA)->recordVies($fr, ViesStatus::Invalid, new \DateTimeImmutable('@' . self::NOW));
        $this->expectException(VatNeedsConfirmation::class);
        $this->svc->issue($id, $this->at());
    }

    public function test_draft_vat_follows_current_customer(): void
    {
        $fr = $this->euCustomer(ViesStatus::Valid);
        $id = $this->draft(['customer_id' => $fr]);
        $this->assertSame(1, $this->doc($id)['reverse_charge']);
        $this->customers($this->companyA)->recordVies($fr, ViesStatus::Unavailable, new \DateTimeImmutable('@' . self::NOW));
        $this->svc->updateDraft($id, ['notes' => 'n']);
        $doc = $this->doc($id);
        $this->assertSame(0, $doc['reverse_charge']);
        $this->assertSame('vies_not_confirmed', $doc['vat_reason']);
        $this->assertSame(11900, $doc['gross_minor']);
        $this->svc->updateDraft($id, ['customer_id' => $this->customer]);
        $this->assertSame('domestic', $this->doc($id)['vat_reason']);
        $this->assertSame('Kunde GmbH', $this->doc($id)['buyer_name']);
    }

    public function test_pln_invoice_requires_rate_and_stores_vat_in_eur(): void
    {
        $pl = $this->customer($this->companyA, ['name' => 'Polska Sp. z o.o.', 'country' => 'PL', 'is_company' => '0', 'default_currency' => 'PLN']);
        $this->assertSame(['eur_rate' => 'document.err_eur_rate_required'], $this->errors(fn () => $this->draft(['customer_id' => $pl])));
        $this->assertSame(['eur_rate' => 'document.err_eur_rate'], $this->errors(fn () => $this->draft(['customer_id' => $pl, 'eur_rate' => '4,26'])));
        $id = $this->draft(['customer_id' => $pl, 'eur_rate' => '4.2650']);
        $this->assertSame('PLN', $this->doc($id)['currency'], 'currency defaults to the customer currency');
        $this->svc->issue($id, $this->at());
        $doc = $this->doc($id);
        $this->assertSame('4.2650', $doc['eur_rate']);
        $this->assertSame(1900, $doc['vat_minor']);
        $this->assertSame(Totals::vatToEur(1900, Totals::parseRate('4.2650')), $doc['vat_eur_minor']);
        $this->assertSame(445, $doc['vat_eur_minor']);
        $this->assertConsistent($doc);
        // EUR invoices take no rate
        $this->assertSame(['eur_rate' => 'document.err_eur_rate'], $this->errors(fn () => $this->draft(['eur_rate' => '4.2'])));
    }

    public function test_pln_reverse_charge_invoice_has_zero_vat(): void
    {
        $pl = $this->customer($this->companyA, ['name' => 'Firma', 'country' => 'PL', 'vat_id' => 'PL1234567890', 'default_currency' => 'PLN']);
        $this->customers($this->companyA)->recordVies($pl, ViesStatus::Valid, new \DateTimeImmutable('@' . self::NOW));
        $id = $this->draft(['customer_id' => $pl, 'eur_rate' => '4.30']);
        $this->svc->issue($id, $this->at());
        $doc = $this->doc($id);
        $this->assertSame(1, $doc['reverse_charge']);
        $this->assertSame(0, $doc['vat_minor']);
        $this->assertSame(0, $doc['vat_eur_minor']);
        $this->assertSame(10000, $doc['gross_minor']);
        $this->assertConsistent($doc);
    }

    // ---- numbering ----

    private function insertIssued(int $companyId, string $number, string $type = 'invoice'): void
    {
        $this->db->exec(
            "INSERT INTO documents (company_id, type, status, number, lang, currency) VALUES (?, ?, 'issued', ?, 'de', 'EUR')",
            [$companyId, $type, $number]
        );
    }

    public function test_issue_skips_numbers_already_taken(): void
    {
        $this->insertIssued($this->companyB, 'RE-2026-001'); // another company: no collision
        $this->assertSame('RE-2026-001', $this->svc->issue($this->draft(), $this->at()));
        $this->insertIssued($this->companyA, 'RE-2026-002');
        $this->assertSame('RE-2026-003', $this->svc->issue($this->draft(), $this->at()));
        $this->insertIssued($this->companyA, 'RE-2026-004');
        $this->insertIssued($this->companyA, 'RE-2026-005');
        $this->insertIssued($this->companyA, 'KO-2026-001', 'credit_note');
        $this->assertSame('RE-2026-006', $this->svc->issue($this->draft(), $this->at()));
        $this->assertSame(6, $this->counter('invoice'));
    }

    public function test_unique_index_race_draws_the_next_number(): void
    {
        $id = $this->draft();
        $armed = true;
        $this->pdo->onPrepare = function (string $sql, HookPdo $pdo) use (&$armed): void {
            if ($armed && str_starts_with($sql, 'UPDATE `documents`') && str_contains($sql, '`status`')) {
                $armed = false; // a concurrent issue took RE-2026-001 after our check
                $pdo->exec("INSERT INTO documents (company_id, type, status, number, lang, currency) VALUES ({$this->companyA}, 'invoice', 'issued', 'RE-2026-001', 'de', 'EUR')");
            }
        };
        $this->assertSame('RE-2026-002', $this->svc->issue($id, $this->at()));
        $this->assertSame('RE-2026-002', $this->doc($id)['number']);
    }

    public function test_collision_attempts_are_bounded(): void
    {
        for ($i = 1; $i <= 50; $i++) {
            $this->insertIssued($this->companyA, sprintf('RE-2026-%03d', $i));
        }
        $id = $this->draft();
        try {
            $this->svc->issue($id, $this->at());
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(ValidationException::class, $e);
        }
        $this->assertSame('draft', $this->doc($id)['status']);
        $this->assertSame(0, $this->counter('invoice'), 'rolled back');
        // with 49 numbers taken the 50th attempt succeeds
        $this->db->exec("DELETE FROM documents WHERE number = 'RE-2026-050'");
        $this->assertSame('RE-2026-050', $this->svc->issue($id, $this->at()));
    }

    public function test_failure_after_next_rolls_back_without_gap(): void
    {
        $id = $this->draft();
        $this->armThrowOnIssueUpdate();
        try {
            $this->svc->issue($id, $this->at());
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }
        $this->pdo->onPrepare = null;
        $doc = $this->doc($id);
        $this->assertSame('draft', $doc['status']);
        $this->assertNull($doc['number']);
        $this->assertNull($doc['seller_json']);
        $this->assertNull($doc['buyer_json']);
        $this->assertNull($doc['issued_at']);
        $this->assertNull($doc['issue_date']);
        $this->assertSame([], $this->audit('document.issue'));
        $this->assertSame(0, $this->counter('invoice'));
        $this->assertSame('RE-2026-001', $this->svc->issue($id, $this->at()));
    }

    public function test_berlin_date_defaults_around_midnight(): void
    {
        $id = $this->draft();
        $this->assertSame('RE-2027-001', $this->svc->issue($id, $this->at('2026-12-31 23:30:00')));
        $doc = $this->doc($id);
        $this->assertSame('2027-01-01', $doc['issue_date']);
        $this->assertSame('2027-01-31', $doc['due_date']);
        $this->assertSame('2026-12-31 23:30:00', $doc['issued_at'], 'issued_at is UTC');
    }

    public function test_explicit_issue_date_picks_the_number_year(): void
    {
        $id = $this->draft(['issue_date' => '2025-12-30', 'service_date' => '2025-12-01']);
        $this->assertSame('RE-2025-001', $this->svc->issue($id, $this->at()));
        $this->assertSame('2026-01-29', $this->doc($id)['due_date']);
        $this->assertSame('2025-12-01', $this->doc($id)['service_date']);
    }

    public function test_due_date_rules(): void
    {
        $this->assertSame(['due_date' => 'document.err_due_before_issue'], $this->errors(fn () => $this->draft(['issue_date' => '2026-11-20', 'due_date' => '2026-11-19'])));
        // explicit due date kept
        $id = $this->draft(['issue_date' => '2026-11-20', 'due_date' => '2026-11-20']);
        $this->svc->issue($id, $this->at());
        $this->assertSame('2026-11-20', $this->doc($id)['due_date']);
        // due set, issue date defaults to a later day at issue time
        $late = $this->draft(['due_date' => '2026-11-23']);
        $this->assertSame(['due_date' => 'document.err_due_before_issue'], $this->errors(fn () => $this->svc->issue($late, $this->at())));
        $this->assertSame('draft', $this->doc($late)['status']);
        // offers get no due date default
        $offer = $this->draft([], null, 'offer');
        $this->assertSame('AN-2026-001', $this->svc->issue($offer, $this->at()));
        $this->assertNull($this->doc($offer)['due_date']);
    }

    public function test_dates_must_be_real_calendar_dates(): void
    {
        foreach (['2026-02-30', '2026-13-01', '26-01-01', '2026-1-1', '2026-01-01 10:00', 'x', '0000-00-00'] as $bad) {
            $this->assertSame(['issue_date' => 'document.err_date'], $this->errors(fn () => $this->draft(['issue_date' => $bad])), $bad);
        }
        $this->assertSame(['service_date' => 'document.err_date'], $this->errors(fn () => $this->draft(['service_date' => '2026-02-29'])));
        $id = $this->draft(['issue_date' => '2028-02-29', 'due_date' => '']);
        $this->assertSame('2028-02-29', $this->doc($id)['issue_date']);
        $this->assertNull($this->doc($id)['due_date']);
    }

    // ---- drafts ----

    public function test_draft_defaults_and_texts(): void
    {
        $id = $this->draft();
        $doc = $this->doc($id);
        $this->assertSame('invoice', $doc['type']);
        $this->assertSame('de', $doc['lang']);
        $this->assertSame('EUR', $doc['currency']);
        $this->assertSame('Hallo, hier deine Rechnung.', $doc['intro_text']);
        $this->assertSame('Danke!', $doc['outro_text']);
        $this->assertNull($doc['notes']);
        $this->assertSame($this->userId, $doc['created_by']);
        $this->assertSame($this->companyA, $doc['company_id']);
        $this->assertSame(11900, $doc['gross_minor']);
        $this->assertConsistent($doc);

        $id2 = $this->draft(['intro_text' => 'Eigene Einleitung', 'outro_text' => '', 'lang' => 'pl']);
        $this->assertSame('Eigene Einleitung', $this->doc($id2)['intro_text']);
        $this->assertNull($this->doc($id2)['outro_text']);
        $this->assertSame('pl', $this->doc($id2)['lang']);
    }

    public function test_update_draft_recomputes_and_replaces_lines(): void
    {
        $id = $this->draft();
        $this->svc->updateDraft($id, ['lines' => [
            $this->line(['name' => 'Zweite', 'qty_milli' => 2500, 'unit_minor' => 3333, 'discount_bp' => 1000, 'vat_bp' => 700]),
            $this->line(['name' => 'Erste', 'description' => 'Details', 'unit' => 'piece']),
        ]]);
        $doc = $this->doc($id);
        $this->assertCount(2, $doc['lines']);
        $this->assertSame(['Zweite', 'Erste'], array_column($doc['lines'], 'name'));
        $this->assertSame([1, 2], array_column($doc['lines'], 'position'));
        $this->assertSame(7499, $doc['lines'][0]['net_minor']); // 33.33 x 2.5 x 0.9 = 74.9925
        $this->assertSame('Details', $doc['lines'][1]['description']);
        $this->assertSame(17499, $doc['net_minor']);
        $this->assertSame(525 + 1900, $doc['vat_minor']);
        $this->assertConsistent($doc);
        $this->assertCount(2, $this->db->all('SELECT id FROM document_lines WHERE document_id = ?', [$id]));

        $this->svc->updateDraft($id, ['notes' => 'nur Notiz']);
        $this->assertCount(2, $this->doc($id)['lines'], 'lines kept when not given');
        $this->assertSame('Hallo, hier deine Rechnung.', $this->doc($id)['intro_text']);
    }

    public function test_line_validation(): void
    {
        $cases = [
            ['lines.0.name', 'document.err_line_name', ['name' => '  ']],
            ['lines.0.name', 'document.err_line_name', ['name' => str_repeat('ü', 191)]],
            ['lines.0.description', 'document.err_line_description', ['description' => str_repeat('a', 2001)]],
            ['lines.0.unit', 'document.err_line_unit', ['unit' => 'liter']],
            ['lines.0.qty_milli', 'document.err_line_qty', ['qty_milli' => 0]],
            ['lines.0.qty_milli', 'document.err_line_qty', ['qty_milli' => 1_000_000_000]],
            ['lines.0.qty_milli', 'document.err_line_qty', ['qty_milli' => 1.5]],
            ['lines.0.unit_minor', 'document.err_line_price', ['unit_minor' => -1]],
            ['lines.0.unit_minor', 'document.err_line_price', ['unit_minor' => 100_000_000_000]],
            ['lines.0.discount_bp', 'document.err_line_discount', ['discount_bp' => 10001]],
            ['lines.0.vat_bp', 'document.err_line_vat', ['vat_bp' => 1600]],
            ['lines.0.item_id', 'document.err_line_item', ['item_id' => 424242]],
        ];
        foreach ($cases as [$field, $key, $override]) {
            $this->assertSame([$field => $key], $this->errors(fn () => $this->draft(['lines' => [$this->line($override)]])), $field);
        }
        $this->assertSame(['lines.1.name' => 'document.err_line_name'], $this->errors(fn () => $this->draft(['lines' => [$this->line(), $this->line(['name' => ''])]])));
        $this->assertSame(['lines' => 'document.err_lines'], $this->errors(fn () => $this->draft(['lines' => []])));
        $this->assertSame(['lines' => 'document.err_lines'], $this->errors(fn () => $this->draft(['lines' => array_fill(0, 201, $this->line())])));
        $this->assertSame(['lines' => 'document.err_lines'], $this->errors(fn () => $this->draft(['lines' => 'x'])));
        $this->assertSame(['lines.0' => 'document.err_lines'], $this->errors(fn () => $this->draft(['lines' => ['x']])));
        $this->assertCount(200, $this->doc($this->draft(['lines' => array_fill(0, 200, $this->line())]))['lines']);
    }

    public function test_header_validation(): void
    {
        $this->assertSame(['lang' => 'document.err_lang'], $this->errors(fn () => $this->draft(['lang' => 'fr'])));
        $this->assertSame(['currency' => 'document.err_currency'], $this->errors(fn () => $this->draft(['currency' => 'GBP'])));
        foreach (['intro_text', 'outro_text', 'notes'] as $f) {
            $this->assertSame([$f => 'document.err_text'], $this->errors(fn () => $this->draft([$f => str_repeat('x', 2001)])), $f);
        }
        $ok = $this->draft(['notes' => str_repeat('ß', 2000)]);
        $this->assertSame(2000, mb_strlen((string) $this->doc($ok)['notes']));
    }

    public function test_unsupported_types_cannot_be_drafted(): void
    {
        foreach (['dunning', 'credit_note', 'bogus'] as $type) {
            try {
                $this->svc->createDraft($type, ['customer_id' => $this->customer, 'lines' => [$this->line()]]);
                $this->fail("$type accepted");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame(0, $this->repo($this->companyA)->count());
    }

    public function test_totals_overflow_is_a_validation_error(): void
    {
        $lines = array_fill(0, 200, $this->line(['qty_milli' => 999_999_999, 'unit_minor' => 99_999_999_999]));
        $this->assertSame(['lines' => 'document.err_total_overflow'], $this->errors(fn () => $this->draft(['lines' => $lines])));
    }

    public function test_bigint_scale_amounts_round_trip(): void
    {
        $id = $this->draft(['lines' => [$this->line(['qty_milli' => 1000, 'unit_minor' => 99_999_999_999])]]);
        $this->svc->issue($id, $this->at());
        $doc = $this->doc($id);
        $this->assertSame(99_999_999_999, $doc['net_minor']);
        $this->assertSame(19_000_000_000, $doc['vat_minor']); // 18 999 999 999.81 -> half-up
        $this->assertSame(118_999_999_999, $doc['gross_minor']);
        $this->assertSame(99_999_999_999, $doc['lines'][0]['unit_minor']);
        $this->assertConsistent($doc);
        $this->svc->addPayment($id, 118_999_999_998, $this->day('2026-11-25'));
        $this->assertSame(1, $this->svc->balanceMinor($id));
    }

    // ---- audit, API surface, App ----

    public function test_audit_rows_carry_ids_only(): void
    {
        $inv = $this->draft();
        $this->svc->issue($inv, $this->at());
        $this->svc->addPayment($inv, 100, $this->day('2026-11-25'), 'Max Mustermann, IBAN DE…');
        $cn = $this->svc->createCorrection($inv);

        $issue = $this->audit('document.issue');
        $this->assertCount(1, $issue);
        $this->assertSame($this->companyA, (int) $issue[0]['company_id']);
        $this->assertSame($this->userId, (int) $issue[0]['user_id']);
        $this->assertSame('2026-11-24 10:00:00', $issue[0]['at']);

        $pay = $this->audit('document.payment_add');
        $this->assertCount(1, $pay);
        $paymentId = (int) $this->db->one('SELECT id FROM payments')['id'];
        $this->assertSame(['document_id' => $inv, 'payment_id' => $paymentId], json_decode((string) $pay[0]['detail'], true));

        $corr = $this->audit('document.correction_create');
        $this->assertCount(1, $corr);
        $this->assertSame(['document_id' => $cn, 'invoice_id' => $inv], json_decode((string) $corr[0]['detail'], true));
        foreach ([$issue, $pay, $corr] as $rows) {
            $this->assertStringNotContainsString('Kunde', (string) $rows[0]['detail']);
            $this->assertStringNotContainsString('Mustermann', (string) $rows[0]['detail']);
        }
    }

    public function test_public_api_has_no_path_to_change_issued_data(): void
    {
        $methods = static fn (string $class): array => array_values(array_filter(
            array_map(fn (\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC)),
            fn (string $n) => $n !== '__construct'
        ));
        $svc = $methods(DocumentService::class);
        sort($svc);
        $this->assertSame([
            'addPayment', 'balanceMinor', 'createCorrection', 'createDraft', 'deleteDraft', 'issue',
            'paymentStatus', 'paymentSummary', 'totalsFor', 'updateDraft',
        ], $svc);
        foreach ($methods(DocumentRepo::class) as $name) {
            $this->assertDoesNotMatchRegularExpression('/payment.*(update|delete|remove|edit)|(update|delete|remove|edit).*payment/i', $name);
        }
    }

    public function test_repo_writers_refuse_issued_documents(): void
    {
        $id = $this->draft();
        $this->svc->issue($id, $this->at());
        $before = $this->doc($id);
        $repo = $this->repo($this->companyA);
        foreach ([
            fn () => $repo->updateDraftRow($id, ['notes' => 'x']),
            fn () => $repo->deleteDraft($id),
            fn () => $repo->replaceLines($id, []),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected DocumentLocked');
            } catch (DocumentLocked) {
            }
        }
        $this->assertSame($before, $this->doc($id));
    }

    public function test_totals_for_matches_stored_totals_of_issued_documents(): void
    {
        $ids = [
            $this->draft(['lines' => [$this->line(['qty_milli' => 333, 'unit_minor' => 3333, 'discount_bp' => 1250]), $this->line(['vat_bp' => 700, 'qty_milli' => 1001]), $this->line(['vat_bp' => 0])]]),
            $this->draft(['lines' => [$this->line(['qty_milli' => 7, 'unit_minor' => 1])]]),
        ];
        foreach ($ids as $id) {
            $this->svc->issue($id, $this->at());
        }
        $cn = $this->svc->createCorrection($ids[0]);
        $this->svc->issue($cn, $this->at());
        foreach ([...$ids, $cn] as $id) {
            $this->assertConsistent($this->doc($id));
        }
    }

    public function test_app_exposes_documents_for_the_current_context(): void
    {
        $m = new \ReflectionMethod(\App\Core\App::class, 'documents');
        $this->assertSame(DocumentService::class, (string) $m->getReturnType());
    }
}
