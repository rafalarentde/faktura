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
use Tests\Support\RecordingStatement;

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
        $this->boot('sqlite::memory:');
    }

    private function boot(string $dsn): void
    {
        $this->pdo = new HookPdo($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 5,
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
            'name' => 'Kunde GmbH', 'address' => "Kundenweg 1\n10115 Berlin", 'country' => 'DE', 'is_company' => '1', 'payment_days' => '30',
            'default_lang' => 'de', 'default_currency' => 'EUR',
        ]);
    }

    private function euCustomer(?ViesStatus $vies, string $currency = 'EUR'): int
    {
        $id = $this->customer($this->companyA, [
            'name' => 'Société SA', 'address' => "1 rue de Rivoli\n75001 Paris", 'country' => 'FR', 'vat_id' => 'FR40303265045', 'default_currency' => $currency,
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
        // Two real connections to one file database: the second commits a payment while the
        // first is inside addPayment's transaction, right before its locked read of the invoice.
        $file = (string) tempnam(sys_get_temp_dir(), 'faktura_doc_');
        try {
            $this->boot('sqlite:' . $file);
            $id = $this->draft();
            $this->svc->issue($id, $this->at());
            $other = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]);
            $armed = true;
            $this->pdo->onPrepare = function (string $sql) use (&$armed, $id, $other): void {
                if ($armed && str_starts_with($sql, 'SELECT * FROM `documents`')) {
                    $armed = false;
                    $other->exec("INSERT INTO payments (company_id, document_id, amount_minor, paid_on) VALUES ({$this->companyA}, $id, 11000, '2026-11-25')");
                }
            };
            $this->assertSame(['amount' => 'document.err_overpayment'], $this->errors(fn () => $this->svc->addPayment($id, 1000, $this->day('2026-11-25'))));
            $this->assertFalse($armed, 'the concurrent payment was committed during addPayment');
            $this->pdo->onPrepare = null;
            $this->assertSame(900, $this->svc->balanceMinor($id), 'the other connection\'s payment is committed and counted');
            $this->svc->addPayment($id, 900, $this->day('2026-11-25'));
            $this->assertSame(['amount' => 'document.err_overpayment'], $this->errors(fn () => $this->svc->addPayment($id, 1, $this->day('2026-11-25'))));
            $this->assertSame(0, $this->svc->balanceMinor($id));
            $this->assertSame(2, (int) $other->query('SELECT COUNT(*) FROM payments')->fetchColumn());
        } finally {
            unset($other);
            $this->pdo->onPrepare = null;
            $this->boot('sqlite::memory:'); // release the file connection
            @unlink($file);
        }
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

    // ---- Fix round 1: lock order, dates, valid_until, encoding ----

    private function mysqlSvc(): DocumentService
    {
        $db = new Db($this->pdo, 'mysql'); // forUpdate() yields ' FOR UPDATE' over the SQLite connection
        $ctx = CompanyContext::forUser($db, $this->userId, $this->companyA);
        $clock = fn (): int => self::NOW;
        $this->pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [RecordingStatement::class]);
        // SQLite cannot run FOR UPDATE: keep it visible as a comment in the recorded statement.
        $this->pdo->onPrepare = fn (string $sql): string => str_ends_with($sql, ' FOR UPDATE')
            ? substr($sql, 0, -strlen(' FOR UPDATE')) . ' /* FOR UPDATE */' : $sql;
        return new DocumentService($db, $ctx, new NumberSequences($db, $ctx, $clock), new DocumentRepo($db, $ctx), new CustomerRepo($db, $ctx), $clock);
    }

    /** @return list<array{0: string, 1: array<int|string, mixed>}> the locking statements, in order */
    private function locks(): array
    {
        return array_values(array_filter(RecordingStatement::$log, fn (array $e): bool => str_contains($e[0], 'FOR UPDATE')));
    }

    /** index of the first locking statement containing $needle (and locking row $id when given) */
    private function lockAt(array $locks, string $needle, ?int $id = null): int
    {
        foreach ($locks as $i => [$sql, $params]) {
            if (str_contains($sql, $needle) && ($id === null || ($params[1] ?? null) === $id)) {
                return $i;
            }
        }
        $this->fail("no lock '$needle' in: " . implode(' | ', array_column($locks, 0)));
    }

    public function test_mysql_lock_order_invoice_before_credit_note(): void
    {
        $inv = $this->draft(['lines' => [$this->line(['qty_milli' => 2000])]]);
        $this->svc->issue($inv, $this->at());
        $cn = $this->svc->createCorrection($inv);
        $this->svc->updateDraft($cn, ['lines' => [$this->line()]]);
        $this->svc->createCorrection($inv); // another draft credit note of the same invoice

        $svc = $this->mysqlSvc();
        RecordingStatement::$log = [];
        $this->assertSame('KO-2026-001', $svc->issue($cn, $this->at()));
        $locks = $this->locks();
        $byId = array_values(array_filter($locks, fn (array $e): bool => str_contains($e[0], 'FROM `documents`') && str_contains($e[0], '(id = ?)')));
        $this->assertSame([$inv, $cn], array_map(fn (array $e): int => $e[1][1], $byId), 'invoice row locked before the credit note row');
        $invoiceLock = $this->lockAt($locks, '(id = ?)', $inv);
        $ownLock = $this->lockAt($locks, '(id = ?)', $cn);
        $scan = $this->lockAt($locks, 'related_document_id = ?');
        $format = $this->lockAt($locks, 'FROM `number_formats`');
        $this->assertLessThan($ownLock, $invoiceLock);
        $this->assertLessThan($scan, $ownLock);
        $this->assertLessThan($format, $scan);
        // numberTaken() is a plain read (no FOR UPDATE), but it does run
        $taken = array_values(array_filter(RecordingStatement::$log, fn (array $e): bool => str_contains($e[0], 'number = ?')));
        $this->assertNotSame([], $taken);
        foreach ($taken as [$sql]) {
            $this->assertStringNotContainsString('FOR UPDATE', $sql);
        }

        RecordingStatement::$log = [];
        $svc->addPayment($inv, 100, $this->day('2026-11-25'));
        $locks = $this->locks();
        $this->assertSame(0, $this->lockAt($locks, '(id = ?)', $inv), 'addPayment locks the invoice first');
        $this->assertGreaterThan(0, $this->lockAt($locks, 'related_document_id = ?'));
        $this->assertGreaterThan(0, $this->lockAt($locks, 'FROM `payments`'));
        $this->assertCount(1, array_filter($locks, fn (array $e): bool => str_contains($e[0], '(id = ?)')), 'no other document row locked');

        RecordingStatement::$log = [];
        $svc->createCorrection($inv);
        $this->assertSame([$inv], array_map(fn (array $e): int => $e[1][1], $this->locks()));
    }

    public function test_future_dated_draft_cannot_be_issued_and_opens_no_bucket(): void
    {
        $count = fn (): int => (int) $this->db->one('SELECT COUNT(*) AS c FROM number_counters')['c'];
        $this->svc->issue($this->draft(), $this->at());
        $before = $count();
        $id = $this->draft(['issue_date' => '2027-03-01']);
        $this->assertSame(['issue_date' => 'document.err_issue_future'], $this->errors(fn () => $this->svc->issue($id, $this->at())));
        $this->assertSame($before, $count(), 'no 2027 counter bucket opened');
        $this->assertSame('draft', $this->doc($id)['status']);
        $tomorrow = $this->draft(['issue_date' => '2026-11-25']);
        $this->assertSame(['issue_date' => 'document.err_issue_future'], $this->errors(fn () => $this->svc->issue($tomorrow, $this->at())));
        // today and backdating stay allowed; "today" is the Berlin date of $at
        $this->svc->updateDraft($id, ['issue_date' => '2026-11-24']);
        $this->assertSame('RE-2026-002', $this->svc->issue($id, $this->at()));
        $this->assertSame('RE-2026-003', $this->svc->issue($tomorrow, $this->at('2026-11-24 23:30:00')));
        $this->assertSame('RE-2026-004', $this->svc->issue($this->draft(['issue_date' => '2026-01-02']), $this->at()));
    }

    public function test_credit_note_dated_before_its_invoice_is_rejected(): void
    {
        $inv = $this->draft(['issue_date' => '2026-11-20']);
        $this->svc->issue($inv, $this->at());
        $cn = $this->svc->createCorrection($inv);
        $this->svc->updateDraft($cn, ['issue_date' => '2026-11-19']);
        $this->assertSame(['issue_date' => 'document.err_issue_before_invoice'], $this->errors(fn () => $this->svc->issue($cn, $this->at())));
        $this->assertSame(0, $this->counter('credit_note'));
        $this->svc->updateDraft($cn, ['issue_date' => '2026-11-20']);
        $this->assertSame('KO-2026-001', $this->svc->issue($cn, $this->at()));
    }

    public function test_valid_until_only_for_offers(): void
    {
        $offer = $this->draft(['issue_date' => '2026-11-20', 'valid_until' => '2026-12-31'], null, 'offer');
        $this->assertSame('2026-12-31', $this->doc($offer)['valid_until']);
        $this->svc->updateDraft($offer, ['notes' => 'x']);
        $this->assertSame('2026-12-31', $this->doc($offer)['valid_until'], 'kept when not given');
        $this->svc->issue($offer, $this->at());
        $this->assertSame('2026-12-31', $this->doc($offer)['valid_until']);

        $this->assertSame(['valid_until' => 'document.err_valid_until_offer_only'], $this->errors(fn () => $this->draft(['valid_until' => '2026-12-31'])));
        $this->assertSame(['valid_until' => 'document.err_valid_until_before_issue'], $this->errors(fn () => $this->draft(['issue_date' => '2026-11-20', 'valid_until' => '2026-11-19'], null, 'offer')));
        $this->assertSame(['valid_until' => 'document.err_date'], $this->errors(fn () => $this->draft(['valid_until' => '2026-02-30'], null, 'offer')));
        $this->assertNull($this->doc($this->draft(['valid_until' => '']))['valid_until'], 'empty means none, for any type');
        // issue date defaulted at issue time after valid_until
        $late = $this->draft(['valid_until' => '2026-11-23'], null, 'offer');
        $this->assertSame(['valid_until' => 'document.err_valid_until_before_issue'], $this->errors(fn () => $this->svc->issue($late, $this->at())));
        // a credit note cannot get one either
        $inv = $this->draft();
        $this->svc->issue($inv, $this->at());
        $cn = $this->svc->createCorrection($inv);
        $this->assertSame(['valid_until' => 'document.err_valid_until_offer_only'], $this->errors(fn () => $this->svc->updateDraft($cn, ['valid_until' => '2026-12-31'])));
    }

    public function test_invalid_utf8_is_a_validation_error_not_a_crash(): void
    {
        $this->assertSame(['notes' => 'document.err_encoding'], $this->errors(fn () => $this->draft(['notes' => "abc\xff"])));
        $this->assertSame(['lines.0.name' => 'document.err_encoding'], $this->errors(fn () => $this->draft(['lines' => [$this->line(['name' => "ab\xc3"])]])));
        $this->assertSame(['lines.0.description' => 'document.err_encoding'], $this->errors(fn () => $this->draft(['lines' => [$this->line(['description' => "\xe2\x82"])]])));

        $id = $this->draft();
        $this->db->exec('UPDATE customers SET address = ? WHERE id = ?', ["Stra\xdfe 1", $this->customer]);
        $this->assertSame(['customer_id' => 'document.err_encoding'], $this->errors(fn () => $this->svc->issue($id, $this->at())));
        $this->assertSame(['customer_id' => 'document.err_encoding'], $this->errors(fn () => $this->svc->updateDraft($id, ['notes' => 'n'])));
        $this->assertSame('draft', $this->doc($id)['status']);
        $this->assertSame(0, $this->counter('invoice'));

        $this->db->exec('UPDATE customers SET address = ? WHERE id = ?', ['Straße 1', $this->customer]);
        $this->db->exec('UPDATE companies SET bank = ? WHERE id = ?', ["Sparkasse \xfc", $this->companyA]);
        $this->assertSame(['company' => 'document.err_encoding'], $this->errors(fn () => $this->svc->issue($id, $this->at())));
        $this->assertSame(0, $this->counter('invoice'));
        $this->db->exec('UPDATE companies SET bank = ? WHERE id = ?', ['Sparkasse', $this->companyA]);
        $this->assertSame('RE-2026-001', $this->svc->issue($id, $this->at()));
    }
    // ---- Fix round 2: snapshot after locks, plain numberTaken, minors ----

    /** Statements recorded since the issue's BEGIN (marker), as [sql, params]. */
    private function statementsAfterBegin(): array
    {
        $i = array_search('BEGIN', array_column(RecordingStatement::$log, 0), true);
        $this->assertNotFalse($i, 'no transaction begun');
        return array_slice(RecordingStatement::$log, $i + 1);
    }

    public function test_mysql_issue_locks_before_any_plain_read_in_its_transaction(): void
    {
        $inv = $this->draft();
        $cn = null;
        $svc = $this->mysqlSvc();
        $this->pdo->onBegin = function (): void {
            RecordingStatement::$log[] = ['BEGIN', []];
        };
        foreach (['invoice', 'credit note'] as $case) {
            RecordingStatement::$log = [];
            $target = $case === 'invoice' ? $inv : $cn;
            $svc->issue($target, $this->at());
            $first = null;
            foreach ($this->statementsAfterBegin() as [$sql, $params]) {
                if (str_starts_with($sql, 'SELECT')) {
                    $first = [$sql, $params];
                    break;
                }
            }
            $this->assertNotNull($first, $case);
            $this->assertStringContainsString('FROM `documents`', $first[0], $case);
            $this->assertStringContainsString('FOR UPDATE', $first[0], "$case: the first read in the transaction is a locking read");
            $this->assertSame($inv, $first[1][1], "$case: invoice row first");
            // the plain pre-read happened before BEGIN
            $before = array_slice(RecordingStatement::$log, 0, (int) array_search('BEGIN', array_column(RecordingStatement::$log, 0), true));
            $this->assertCount(1, $before, $case);
            $this->assertStringNotContainsString('FOR UPDATE', $before[0][0]);
            $cn ??= $this->svc->createCorrection($inv);
        }
        $this->pdo->onBegin = null;
    }

    public function test_issue_sees_lines_committed_while_it_waited_for_the_lock(): void
    {
        // Two real connections, WAL (snapshot reads like InnoDB). Another request's updateDraft
        // commits new lines/currency after issue()'s pre-read, right before its row lock.
        $file = (string) tempnam(sys_get_temp_dir(), 'faktura_doc_');
        try {
            $this->boot('sqlite:' . $file);
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $id = $this->draft();
            $pl = $this->customer($this->companyA, ['name' => 'Polska', 'country' => 'PL', 'is_company' => '0']);
            $pdo2 = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_TIMEOUT => 5]);
            $db2 = new Db($pdo2, 'sqlite');
            $ctx2 = CompanyContext::forUser($db2, $this->userId, $this->companyA);
            $other = new DocumentService($db2, $ctx2, new NumberSequences($db2, $ctx2), new DocumentRepo($db2, $ctx2), new CustomerRepo($db2, $ctx2));
            $armed = true;
            $this->pdo->onPrepare = function (string $sql) use (&$armed, $other, $id, $pl): void {
                if ($armed && str_contains($sql, 'FROM `documents`') && str_contains($sql, '(id = ?)')) {
                    $armed = false;
                    $other->updateDraft($id, ['customer_id' => $pl, 'currency' => 'PLN', 'eur_rate' => '4.0000',
                        'lines' => [$this->line(['qty_milli' => 3000])]]);
                }
            };
            $this->assertSame('RE-2026-001', $this->svc->issue($id, $this->at()));
            $this->assertFalse($armed);
            $this->pdo->onPrepare = null;
            $doc = $this->doc($id);
            $this->assertSame('PLN', $doc['currency']);
            $this->assertSame(3000, $doc['lines'][0]['qty_milli']);
            $this->assertSame(30000, $doc['net_minor'], 'totals from the NEW lines');
            $this->assertSame(35700, $doc['gross_minor']);
            $this->assertSame(1425, $doc['vat_eur_minor']);
            $this->assertSame('Polska', $doc['buyer']['name']);
            $this->assertConsistent($doc);
        } finally {
            unset($other, $ctx2, $db2, $pdo2);
            $this->pdo->onPrepare = null;
            $this->boot('sqlite::memory:');
            @unlink($file);
            @unlink($file . '-wal');
            @unlink($file . '-shm');
        }
    }

    public function test_payment_note_must_be_valid_utf8(): void
    {
        $id = $this->draft();
        $this->svc->issue($id, $this->at());
        $this->assertSame(['note' => 'document.err_note'], $this->errors(fn () => $this->svc->addPayment($id, 100, $this->day('2026-11-25'), "Zahlung \xff")));
        $this->svc->addPayment($id, 100, $this->day('2026-11-25'), str_repeat('ü', 190));
        $this->assertSame(str_repeat('ü', 190), $this->db->one('SELECT note FROM payments')['note']);
    }

    public function test_corrupted_customer_does_not_block_editing_a_credit_note(): void
    {
        $inv = $this->draft();
        $this->svc->issue($inv, $this->at());
        $cn = $this->svc->createCorrection($inv);
        $this->db->exec('UPDATE customers SET address = ? WHERE id = ?', ["Stra\xdfe 1", $this->customer]);
        $this->svc->updateDraft($cn, ['notes' => 'Storno', 'lines' => [$this->line(['qty_milli' => 500])]]);
        $this->assertSame('Storno', $this->doc($cn)['notes']);
        $this->svc->issue($cn, $this->at());
        $this->assertSame('Kunde GmbH', $this->doc($cn)['buyer']['name']);
        // invoices for that customer still get the clean 422
        $this->assertSame(['customer_id' => 'document.err_encoding'], $this->errors(fn () => $this->draft()));
    }

    // ---- R29: mandatory seller and buyer data at issue (Task 17 fix round 1) ----

    /** @param array<string, mixed> $company columns of company A to change before issuing */
    private function assertIssueRefused(array $company, array $customer, array $expected): void
    {
        foreach ($company as $col => $v) {
            $this->db->exec("UPDATE companies SET $col = ? WHERE id = ?", [$v, $this->companyA]);
        }
        $cid = $customer === [] ? $this->customer : $this->customer($this->companyA, $customer);
        $id = $this->draft(['customer_id' => $cid]);
        $before = $this->counter('invoice');
        try {
            $this->svc->issue($id, $this->at());
            $this->fail('issue must be refused');
        } catch (ValidationException $e) {
            $this->assertSame($expected, $e->errors);
        }
        $this->assertSame($before, $this->counter('invoice'), 'no number consumed');
        $doc = $this->repo($this->companyA)->find($id);
        $this->assertSame(['draft', null], [$doc['status'], $doc['number']]);
    }

    public function test_issue_requires_seller_name(): void
    {
        $this->assertIssueRefused(['name' => '  '], [], ['seller' => 'document.err_seller_incomplete']);
    }

    public function test_issue_requires_seller_address(): void
    {
        $this->assertIssueRefused(['address' => ''], [], ['seller' => 'document.err_seller_incomplete']);
    }

    public function test_issue_requires_a_seller_tax_id(): void
    {
        $this->assertIssueRefused(['tax_number' => null, 'vat_id' => ' '], [], ['seller' => 'document.err_seller_incomplete']);
    }

    public function test_issue_requires_buyer_address(): void
    {
        $this->assertIssueRefused([], ['name' => 'Ohne Adresse GmbH', 'address' => ''], ['buyer' => 'document.err_buyer_incomplete']);
    }

    public function test_seller_and_buyer_errors_are_reported_together(): void
    {
        $this->assertIssueRefused(['address' => null], ['name' => 'Ohne Adresse GmbH', 'address' => ''],
            ['seller' => 'document.err_seller_incomplete', 'buyer' => 'document.err_buyer_incomplete']);
    }

    public function test_reverse_charge_requires_seller_vat_id(): void
    {
        $this->db->exec('UPDATE companies SET vat_id = NULL WHERE id = ?', [$this->companyA]);
        $eu = $this->euCustomer(ViesStatus::Valid);
        $id = $this->draft(['customer_id' => $eu]);
        $before = $this->counter('invoice');
        try {
            $this->svc->issue($id, $this->at());
            $this->fail('reverse charge without seller VAT ID');
        } catch (ValidationException $e) {
            $this->assertSame(['seller_vat' => 'document.err_seller_vat_required'], $e->errors);
        }
        $this->assertSame($before, $this->counter('invoice'));
        // A domestic invoice is fine with the tax number alone.
        $this->svc->issue($this->draft(), $this->at());
    }

    public function test_seller_with_only_vat_id_or_only_tax_number_may_issue(): void
    {
        $this->db->exec('UPDATE companies SET tax_number = NULL WHERE id = ?', [$this->companyA]);
        $this->assertSame('RE-2026-001', $this->svc->issue($this->draft(), $this->at()));
        $this->db->exec("UPDATE companies SET tax_number = '27/123/45678', vat_id = NULL WHERE id = ?", [$this->companyA]);
        $this->assertSame('RE-2026-002', $this->svc->issue($this->draft(), $this->at()));
    }

    public function test_credit_note_checks_the_seller_and_inherits_the_buyer(): void
    {
        $inv = $this->draft();
        $this->svc->issue($inv, $this->at());
        // The customer row loses its address later: the credit note uses the invoice's buyer snapshot.
        $this->db->exec("UPDATE customers SET address = '' WHERE id = ?", [$this->customer]);
        $cn = $this->svc->createCorrection($inv);
        $this->assertSame('KO-2026-001', $this->svc->issue($cn, $this->at()));

        $cn2 = $this->svc->createCorrection($inv);
        $this->svc->updateDraft($cn2, ['lines' => [$this->line(['unit_minor' => 1])]]);
        $this->db->exec('UPDATE companies SET tax_number = NULL, vat_id = NULL WHERE id = ?', [$this->companyA]);
        $before = $this->counter('credit_note');
        try {
            $this->svc->issue($cn2, $this->at());
            $this->fail('credit note with incomplete seller');
        } catch (ValidationException $e) {
            $this->assertSame(['seller' => 'document.err_seller_incomplete'], $e->errors);
        }
        $this->assertSame($before, $this->counter('credit_note'));
    }
}
