<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Core\Db;
use App\Core\Migrator;
use App\Domain\AccessSuspended;
use App\Domain\DocumentNotFound;
use App\Domain\DocumentService;
use App\Domain\Line;
use App\Domain\NumberSequences;
use App\Domain\Totals;
use App\Domain\ValidationException;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Tenant\CompanyContext;
use App\Vies\ViesStatus;
use PHPUnit\Framework\TestCase;

/**
 * Offers (Task 19): issuing with the 'offer' number sequence, the stored status (open, sent,
 * accepted, rejected) and the computed 'expired', and the conversion of an accepted offer into a
 * DRAFT invoice. Invoice rules are tested in DocumentServiceTest.
 */
final class OfferTest extends TestCase
{
    private const NOW = 1_795_514_400; // 2026-11-24 10:00:00 UTC = 11:00 Berlin

    private Db $db;
    private int $userId;
    private int $companyA;
    private int $companyB;
    private DocumentService $svc;
    private int $customer;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->db = new Db($pdo, 'sqlite');
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->run();
        $this->db->exec('INSERT INTO users (email, password_hash) VALUES (?, ?)', ['o@example.com', 'x']);
        $this->userId = $this->db->lastId();
        $this->companyA = $this->company('A GmbH');
        $this->companyB = $this->company('B GmbH');
        $this->svc = $this->svc($this->companyA);
        $this->customer = $this->customer($this->companyA);
    }

    // ---- helpers ----

    /** @param array<string, mixed> $over */
    private function company(string $name, array $over = []): int
    {
        $row = $over + ['name' => $name, 'address' => "Hauptstr. 1\n10115 Berlin", 'tax_number' => '27/123/45678', 'vat_id' => 'DE123456789',
            'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'bank' => 'Bank', 'intro_text' => 'Hallo, hier dein Beleg.',
            'outro_text' => 'Danke!', 'default_payment_days' => 14, 'access_status' => 'active', 'access_until' => null];
        $this->db->exec('INSERT INTO companies (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
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

    private function repo(int $companyId): DocumentRepo
    {
        return new DocumentRepo($this->db, $this->ctx($companyId));
    }

    /** @param array<string, mixed> $data */
    private function customer(int $companyId, array $data = []): int
    {
        return (new CustomerRepo($this->db, $this->ctx($companyId)))->save($data + [
            'name' => 'Kunde GmbH', 'address' => "Kundenweg 1\n10115 Berlin", 'country' => 'DE', 'is_company' => '1', 'payment_days' => '30',
            'default_lang' => 'de', 'default_currency' => 'EUR',
        ]);
    }

    /** @return array<string, mixed> */
    private function line(array $o = []): array
    {
        return $o + ['name' => 'Beratung', 'unit' => 'hour', 'qty_milli' => 1000, 'unit_minor' => 10000, 'discount_bp' => 0, 'vat_bp' => 1900];
    }

    /** @param array<string, mixed> $extra */
    private function draft(array $extra = [], ?DocumentService $svc = null, string $type = 'offer'): int
    {
        return ($svc ?? $this->svc)->createDraft($type, $extra + ['customer_id' => $this->customer, 'lines' => [$this->line()]]);
    }

    private function at(string $utc = '2026-11-24 10:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $extra */
    private function issued(array $extra = [], ?DocumentService $svc = null): int
    {
        $svc ??= $this->svc;
        $id = $this->draft($extra, $svc);
        $svc->issue($id, $this->at());
        return $id;
    }

    private function accepted(array $extra = []): int
    {
        $id = $this->issued($extra);
        $this->svc->setOfferStatus($id, 'accepted');
        return $id;
    }

    /** @return array<string, mixed> */
    private function doc(int $id, int $company = 0): array
    {
        return $this->repo($company ?: $this->companyA)->find($id) ?? $this->fail('document missing');
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

    private function today(string $ymd = '2026-11-24'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd . ' 12:00:00', new \DateTimeZone('Europe/Berlin'));
    }

    // ---- brief tests ----

    public function test_only_accepted_offer_can_be_converted(): void
    {
        $draft = $this->draft();
        $open = $this->issued();
        $sent = $this->issued();
        $this->svc->setOfferStatus($sent, 'sent');
        $rejected = $this->issued();
        $this->svc->setOfferStatus($rejected, 'rejected');
        foreach ([$draft, $open, $sent, $rejected] as $id) {
            $this->assertSame(['document' => 'document.err_offer_not_accepted'], $this->errors(fn () => $this->svc->convertOffer($id)));
        }
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) AS c FROM documents WHERE type = 'invoice'")['c']);

        $accepted = $this->accepted();
        $this->assertGreaterThan(0, $this->svc->convertOffer($accepted));
    }

    public function test_conversion_copies_lines_customer_currency_and_links_back(): void
    {
        $pl = $this->customer($this->companyA, ['name' => 'Kowalski Sp. z o.o.', 'country' => 'PL', 'vat_id' => 'PL1234567890', 'default_lang' => 'pl', 'default_currency' => 'PLN']);
        $this->db->exec("INSERT INTO items (company_id, type, unit, price_minor, currency, vat_bp, is_active) VALUES (?, 'service', 'hour', 100, 'EUR', 1900, 1)", [$this->companyA]);
        $item = $this->db->lastId();
        $lines = [
            $this->line(['name' => 'Beratung', 'description' => "Zeile 1\nZeile 2", 'qty_milli' => 2500, 'unit_minor' => 45050, 'discount_bp' => 1250, 'vat_bp' => 1900, 'item_id' => $item]),
            $this->line(['name' => 'Bücher', 'unit' => 'piece', 'qty_milli' => 5000, 'unit_minor' => 1200, 'vat_bp' => 700]),
            $this->line(['name' => 'Pauschale', 'unit' => 'flat', 'qty_milli' => 1000, 'unit_minor' => 6000, 'vat_bp' => 0]),
        ];
        $offer = $this->svc->createDraft('offer', ['customer_id' => $pl, 'currency' => 'PLN', 'lang' => 'pl', 'eur_rate' => '4.2650',
            'issue_date' => '2026-11-20', 'notes' => 'intern', 'intro_text' => 'Angebotstext', 'outro_text' => 'Angebots-Schluss', 'lines' => $lines]);
        $this->svc->issue($offer, $this->at(), true);
        $this->svc->setOfferStatus($offer, 'accepted');

        $invoice = $this->svc->convertOffer($offer);
        $o = $this->doc($offer);
        $i = $this->doc($invoice);
        $this->assertSame('invoice', $i['type']);
        $this->assertSame($offer, $i['related_document_id']);
        foreach (['customer_id', 'lang', 'currency', 'eur_rate'] as $f) {
            $this->assertSame($o[$f], $i[$f], $f);
        }
        $this->assertSame('PLN', $i['currency']);
        $this->assertSame('pl', $i['lang']);
        $this->assertCount(3, $i['lines']);
        foreach ($o['lines'] as $n => $ol) {
            foreach (['name', 'description', 'unit', 'qty_milli', 'unit_minor', 'discount_bp', 'vat_bp', 'item_id', 'net_minor', 'position'] as $f) {
                $this->assertSame($ol[$f], $i['lines'][$n][$f], "line $n $f");
            }
            $this->assertNotSame($ol['id'], $i['lines'][$n]['id'], 'new rows, not shared');
        }
        // same VAT treatment and the same amounts as the offer shows
        $this->assertSame($o['net_minor'], $i['net_minor']);
        $this->assertSame($o['vat_minor'], $i['vat_minor']);
        $this->assertSame($o['gross_minor'], $i['gross_minor']);
        $this->assertSame($o['vat_eur_minor'], $i['vat_eur_minor']);
        // the offer itself did not change
        $this->assertSame('accepted', $o['offer_status']);
        $this->assertCount(3, $o['lines']);
        $this->assertSame([['document_id' => $invoice, 'offer_id' => $offer]], array_map(
            fn (array $r): array => ['document_id' => $invoice, 'offer_id' => json_decode($r['detail'], true)['offer_id']],
            $this->audit('document.offer_convert')
        ));
        $this->assertSame($invoice, json_decode($this->audit('document.offer_convert')[0]['detail'], true)['invoice_id']);
    }

    public function test_converted_invoice_is_draft_without_number(): void
    {
        $offer = $this->accepted(['issue_date' => '2026-11-20', 'valid_until' => '2026-12-20', 'notes' => 'interne Notiz', 'intro_text' => 'Angebotstext', 'outro_text' => 'Angebots-Schluss']);
        $invoice = $this->svc->convertOffer($offer);
        $i = $this->doc($invoice);
        $this->assertSame('draft', $i['status']);
        $this->assertNull($i['number']);
        $this->assertNull($i['issued_at']);
        $this->assertNull($i['issue_date']);
        $this->assertNull($i['due_date']);
        $this->assertNull($i['valid_until']);
        $this->assertNull($i['offer_status']);
        $this->assertNull($i['notes']);
        $this->assertSame('Hallo, hier dein Beleg.', $i['intro_text'], 'company default, not the offer wording');
        $this->assertSame('Danke!', $i['outro_text']);
        $this->assertNull($i['seller']);
        $this->assertSame(0, (new NumberSequences($this->db, $this->ctx($this->companyA)))->lastCounter('invoice', $this->today()), 'no invoice number consumed');
        // it can then be issued like any invoice
        $this->assertSame('RE-2026-001', $this->svc->issue($invoice, $this->at()));
    }

    public function test_expired_status_computed_from_valid_until(): void
    {
        $id = $this->issued(['issue_date' => '2026-11-10', 'valid_until' => '2026-11-23']);
        $doc = $this->doc($id);
        $this->assertSame('open', $doc['offer_status'], 'never stored as expired');
        $this->assertSame('expired', $this->svc->offerStatus($doc, $this->today('2026-11-24')));
        $this->assertSame('open', $this->svc->offerStatus($doc, $this->today('2026-11-23')), 'valid until today is still valid');
        $this->assertSame('open', $this->svc->offerStatus($doc, $this->today('2026-11-22')));
    }

    // ---- statuses ----

    public function test_issue_sets_open_and_drafts_have_no_status(): void
    {
        $id = $this->draft();
        $this->assertNull($this->doc($id)['offer_status']);
        $this->assertSame('draft', $this->svc->offerStatus($this->doc($id), $this->today()));
        $this->svc->issue($id, $this->at());
        $this->assertSame('open', $this->doc($id)['offer_status']);
        $this->assertSame('open', $this->svc->offerStatus($this->doc($id), $this->today()));
        // invoices never get an offer status
        $inv = $this->draft([], null, 'invoice');
        $this->svc->issue($inv, $this->at());
        $this->assertNull($this->doc($inv)['offer_status']);
    }

    public function test_status_transitions_and_audit(): void
    {
        $id = $this->issued();
        foreach (['sent', 'accepted', 'open', 'rejected', 'accepted', 'sent', 'open'] as $to) {
            $before = $this->doc($id)['offer_status'];
            $this->svc->setOfferStatus($id, $to);
            $this->assertSame($to, $this->doc($id)['offer_status']);
            $this->assertSame($to, $this->svc->offerStatus($this->doc($id), $this->today()));
            $rows = $this->audit('document.offer_status');
            $last = json_decode($rows[array_key_last($rows)]['detail'], true);
            $this->assertSame(['document_id' => $id, 'from' => $before, 'to' => $to], $last);
        }
        $this->assertCount(7, $this->audit('document.offer_status'));
    }

    public function test_status_change_is_validated(): void
    {
        $id = $this->issued();
        foreach (['expired', 'draft', 'issued', '', 'ACCEPTED', 'sent '] as $bad) {
            $this->assertSame(['status' => 'document.err_offer_status'], $this->errors(fn () => $this->svc->setOfferStatus($id, $bad)), $bad);
        }
        $this->assertSame('open', $this->doc($id)['offer_status']);
        $this->assertSame(['document' => 'document.err_offer_draft_status'], $this->errors(fn () => $this->svc->setOfferStatus($this->draft(), 'sent')));
    }

    public function test_status_only_for_offers_of_the_active_company(): void
    {
        $invoice = $this->draft([], null, 'invoice');
        $this->svc->issue($invoice, $this->at());
        $foreignSvc = $this->svc($this->companyB);
        $foreignCustomer = $this->customer($this->companyB);
        $foreign = $foreignSvc->createDraft('offer', ['customer_id' => $foreignCustomer, 'lines' => [$this->line()]]);
        $foreignSvc->issue($foreign, $this->at());
        foreach ([$invoice, $foreign, 999999] as $id) {
            try {
                $this->svc->setOfferStatus($id, 'sent');
                $this->fail('expected DocumentNotFound');
            } catch (DocumentNotFound) {
                $this->addToAssertionCount(1);
            }
            try {
                $this->svc->convertOffer($id);
                $this->fail('expected DocumentNotFound');
            } catch (DocumentNotFound) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('open', $this->doc($foreign, $this->companyB)['offer_status']);
        $this->assertNull($this->doc($invoice)['offer_status']);
    }

    public function test_offer_with_a_linked_invoice_cannot_leave_accepted(): void
    {
        $id = $this->accepted();
        $invoice = $this->svc->convertOffer($id);
        foreach (['open', 'sent', 'rejected'] as $to) {
            $this->assertSame(['status' => 'document.err_offer_converted'], $this->errors(fn () => $this->svc->setOfferStatus($id, $to)));
        }
        $this->svc->setOfferStatus($id, 'accepted'); // no change is fine
        $this->assertSame('accepted', $this->doc($id)['offer_status']);
        // also once the invoice is issued, and deleting the draft frees the offer again
        $this->svc->issue($invoice, $this->at());
        $this->assertSame(['status' => 'document.err_offer_converted'], $this->errors(fn () => $this->svc->setOfferStatus($id, 'open')));
    }

    public function test_deleting_the_converted_draft_frees_the_offer(): void
    {
        $id = $this->accepted();
        $invoice = $this->svc->convertOffer($id);
        $this->svc->deleteDraft($invoice);
        $this->svc->setOfferStatus($id, 'sent');
        $this->assertSame('sent', $this->doc($id)['offer_status']);
    }

    public function test_expired_boundary_and_only_open_or_sent(): void
    {
        $mk = function (string $status): array {
            $id = $this->issued(['issue_date' => '2026-11-01', 'valid_until' => '2026-11-23']);
            $this->svc->setOfferStatus($id, $status);
            return $this->doc($id);
        };
        $today = $this->today('2026-11-24');
        $this->assertSame('expired', $this->svc->offerStatus($mk('open'), $today));
        $this->assertSame('expired', $this->svc->offerStatus($mk('sent'), $today));
        $this->assertSame('accepted', $this->svc->offerStatus($mk('accepted'), $today));
        $this->assertSame('rejected', $this->svc->offerStatus($mk('rejected'), $today));
        // the Berlin date counts, not the UTC date: 2026-11-23 23:30 UTC is already 2026-11-24 in Berlin
        $late = new \DateTimeImmutable('2026-11-23 23:30:00', new \DateTimeZone('UTC'));
        $this->assertSame('expired', $this->svc->offerStatus($mk('open'), $late));
        $early = new \DateTimeImmutable('2026-11-22 23:30:00', new \DateTimeZone('UTC'));
        $this->assertSame('open', $this->svc->offerStatus($mk('open'), $early), 'Berlin date is 2026-11-23: valid until today');
    }

    public function test_expired_offer_can_still_be_accepted_and_converted(): void
    {
        $id = $this->issued(['issue_date' => '2026-11-01', 'valid_until' => '2026-11-02']);
        $this->svc->setOfferStatus($id, 'accepted');
        $this->assertGreaterThan(0, $this->svc->convertOffer($id));
    }

    // ---- valid_until, due date, gate ----

    public function test_valid_until_defaults_to_thirty_days_after_issue_date(): void
    {
        $id = $this->issued(['issue_date' => '2026-11-20']);
        $this->assertSame('2026-12-20', $this->doc($id)['valid_until']);
        $today = $this->issued();
        $this->assertSame('2026-12-24', $this->doc($today)['valid_until'], 'issue date defaults to today (Berlin)');
        $explicit = $this->issued(['issue_date' => '2026-11-20', 'valid_until' => '2026-11-20']);
        $this->assertSame('2026-11-20', $this->doc($explicit)['valid_until']);
    }

    public function test_valid_until_validation(): void
    {
        $this->assertSame(['valid_until' => 'document.err_valid_until_before_issue'], $this->errors(fn () => $this->draft(['issue_date' => '2026-11-20', 'valid_until' => '2026-11-19'])));
        $this->assertSame(['valid_until' => 'document.err_valid_until_offer_only'], $this->errors(fn () => $this->draft(['valid_until' => '2026-12-31'], null, 'invoice')));
        // draft without issue date: a valid_until before today is refused at issue
        $id = $this->draft(['valid_until' => '2026-11-01']);
        $this->assertSame(['valid_until' => 'document.err_valid_until_before_issue'], $this->errors(fn () => $this->svc->issue($id, $this->at())));
        $this->assertSame('draft', $this->doc($id)['status']);
        $this->assertSame(0, (new NumberSequences($this->db, $this->ctx($this->companyA)))->lastCounter('offer', $this->today()));
    }

    public function test_offers_have_no_due_date(): void
    {
        $id = $this->draft(['issue_date' => '2026-11-20', 'due_date' => '2026-12-31']);
        $this->assertNull($this->doc($id)['due_date'], 'a posted due date is ignored for offers');
        $this->svc->issue($id, $this->at());
        $this->assertNull($this->doc($id)['due_date']);
        $this->svc->updateDraft($this->draft(), ['due_date' => '2026-12-31']);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) AS c FROM documents WHERE type = 'offer' AND due_date IS NOT NULL")['c']);
    }

    public function test_offers_are_issued_with_names_only_but_invoices_are_not(): void
    {
        $bare = $this->company('Bare GmbH', ['address' => null, 'tax_number' => null, 'vat_id' => null, 'iban' => null]);
        $svc = $this->svc($bare);
        $customer = $this->customer($bare, ['address' => '']);
        $offer = $svc->createDraft('offer', ['customer_id' => $customer, 'lines' => [$this->line()]]);
        $this->assertSame('AN-2026-001', $svc->issue($offer, $this->at()));
        $invoice = $svc->createDraft('invoice', ['customer_id' => $customer, 'lines' => [$this->line()]]);
        $this->assertSame(['seller' => 'document.err_seller_incomplete', 'buyer' => 'document.err_buyer_incomplete'], $this->errors(fn () => $svc->issue($invoice, $this->at())));
    }

    public function test_offer_needs_both_names(): void
    {
        $blank = $this->company('Nameless', ['name' => '   ']);
        $svc = $this->svc($blank);
        $customer = $this->customer($blank);
        $id = $svc->createDraft('offer', ['customer_id' => $customer, 'lines' => [$this->line()]]);
        $this->assertArrayHasKey('seller', $this->errors(fn () => $svc->issue($id, $this->at())));
        $this->assertSame(0, (new NumberSequences($this->db, $this->ctx($blank)))->lastCounter('offer', $this->today()));
    }

    public function test_vies_confirmation_does_not_block_offers(): void
    {
        $fr = $this->customer($this->companyA, ['name' => 'Société SA', 'address' => "1 rue de Rivoli\n75001 Paris", 'country' => 'FR', 'vat_id' => 'FR40303265045']);
        (new CustomerRepo($this->db, $this->ctx($this->companyA)))->recordVies($fr, ViesStatus::Unavailable, new \DateTimeImmutable('@' . self::NOW), 'FR', 'FR40303265045');
        $offer = $this->svc->createDraft('offer', ['customer_id' => $fr, 'lines' => [$this->line()]]);
        $this->assertSame('vies_not_confirmed', $this->doc($offer)['vat_reason']);
        $this->svc->issue($offer, $this->at()); // no confirmation needed
        $d = $this->doc($offer);
        $this->assertSame('issued', $d['status']);
        $this->assertSame(0, $d['reverse_charge'], 'German VAT');
        $this->assertSame('vies_not_confirmed', $d['vat_reason'], 'the reason is recorded');
        $this->assertSame(11900, $d['gross_minor']);
        $this->assertFalse(json_decode($this->audit('document.issue')[0]['detail'], true)['vat_confirmed']);
        // an invoice for the same customer still needs the confirmation
        $inv = $this->svc->createDraft('invoice', ['customer_id' => $fr, 'lines' => [$this->line()]]);
        $this->expectException(\App\Domain\VatNeedsConfirmation::class);
        $this->svc->issue($inv, $this->at());
    }

    public function test_reverse_charge_offer_matches_the_vat_of_the_later_invoice(): void
    {
        $fr = $this->customer($this->companyA, ['name' => 'Société SA', 'address' => "1 rue de Rivoli\n75001 Paris", 'country' => 'FR', 'vat_id' => 'FR40303265045']);
        (new CustomerRepo($this->db, $this->ctx($this->companyA)))->recordVies($fr, ViesStatus::Valid, new \DateTimeImmutable('@' . self::NOW), 'FR', 'FR40303265045');
        $offer = $this->svc->createDraft('offer', ['customer_id' => $fr, 'lines' => [$this->line()]]);
        $this->svc->issue($offer, $this->at());
        $o = $this->doc($offer);
        $this->assertSame(1, $o['reverse_charge']);
        $this->assertSame(10000, $o['gross_minor']);
        $this->svc->setOfferStatus($offer, 'accepted');
        $i = $this->doc($this->svc->convertOffer($offer));
        $this->assertSame([1, 10000], [$i['reverse_charge'], $i['gross_minor']]);
        $this->assertSame($o['vat_note_key'], $i['vat_note_key']);
    }

    public function test_suspended_company_cannot_issue_an_offer_but_can_prepare_and_decide(): void
    {
        $id = $this->issued();
        $this->db->exec("UPDATE companies SET access_status = 'suspended' WHERE id = ?", [$this->companyA]);
        $this->svc = $this->svc($this->companyA); // a new context reads the new access status
        $draft = $this->draft();
        try {
            $this->svc->issue($draft, $this->at());
            $this->fail('expected AccessSuspended');
        } catch (AccessSuspended) {
            $this->assertSame('draft', $this->doc($draft)['status']);
        }
        $this->svc->setOfferStatus($id, 'accepted');
        $this->assertGreaterThan(0, $this->svc->convertOffer($id), 'a draft invoice can be prepared');
    }

    // ---- numbering ----

    public function test_offers_use_their_own_number_sequence(): void
    {
        $i1 = $this->draft([], null, 'invoice');
        $o1 = $this->draft();
        $o2 = $this->draft();
        $this->assertSame('RE-2026-001', $this->svc->issue($i1, $this->at()));
        $this->assertSame('AN-2026-001', $this->svc->issue($o1, $this->at()));
        $this->assertSame('AN-2026-002', $this->svc->issue($o2, $this->at()));
        $i2 = $this->draft([], null, 'invoice');
        $this->assertSame('RE-2026-002', $this->svc->issue($i2, $this->at()));
    }

    // ---- conversion protection ----

    public function test_conversion_double_click_returns_the_same_draft(): void
    {
        $offer = $this->accepted();
        $first = $this->svc->convertOffer($offer);
        $this->assertSame($first, $this->svc->convertOffer($offer));
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) AS c FROM documents WHERE type = 'invoice'")['c']);
        $this->assertCount(1, $this->audit('document.offer_convert'), 'the repeated call writes nothing');
    }

    public function test_second_draft_after_the_first_invoice_was_issued(): void
    {
        $offer = $this->accepted();
        $first = $this->svc->convertOffer($offer);
        $this->svc->issue($first, $this->at());
        $second = $this->svc->convertOffer($offer);
        $this->assertNotSame($first, $second);
        $this->assertSame('draft', $this->doc($second)['status']);
        $this->assertSame($second, $this->svc->convertOffer($offer), 'and again the same draft');
        $linked = $this->repo($this->companyA)->list(['type' => 'invoice', 'related_document_id' => $offer]);
        $this->assertCount(2, $linked);
    }

    public function test_conversion_is_atomic(): void
    {
        $offer = $this->accepted();
        // a customer without default... break the customer link: conversion fails, nothing is left behind
        $this->db->exec('UPDATE documents SET customer_id = 999999 WHERE id = ?', [$offer]);
        $this->assertSame(['customer_id' => 'document.err_customer'], $this->errors(fn () => $this->svc->convertOffer($offer)));
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) AS c FROM documents WHERE type = 'invoice'")['c']);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) AS c FROM document_lines WHERE document_id NOT IN (SELECT id FROM documents)")['c']);
        $this->assertSame([], $this->audit('document.offer_convert'));
    }

    // ---- internal link ----

    public function test_related_document_id_from_input_is_ignored(): void
    {
        $other = $this->issued();
        $id = $this->draft(['related_document_id' => $other, 'type' => 'invoice', 'company_id' => $this->companyB, 'offer_status' => 'accepted', 'status' => 'issued', 'number' => 'X-1'], null, 'invoice');
        $d = $this->doc($id);
        $this->assertNull($d['related_document_id']);
        $this->assertSame(['invoice', 'draft', null, null], [$d['type'], $d['status'], $d['number'], $d['offer_status']]);
        $this->assertSame($this->companyA, $d['company_id']);
        $this->svc->updateDraft($id, ['related_document_id' => $other, 'offer_status' => 'accepted']);
        $d = $this->doc($id);
        $this->assertNull($d['related_document_id']);
        $this->assertNull($d['offer_status']);
        $o = $this->draft(['offer_status' => 'accepted']);
        $this->assertNull($this->doc($o)['offer_status'], 'a draft offer never takes a status from input');
    }

    public function test_offers_are_immutable_once_issued(): void
    {
        $id = $this->issued();
        $this->expectException(\App\Domain\DocumentLocked::class);
        $this->svc->updateDraft($id, ['notes' => 'x']);
    }

    public function test_offer_cannot_be_deleted_or_corrected_once_issued(): void
    {
        $id = $this->issued();
        try {
            $this->svc->deleteDraft($id);
            $this->fail('expected DocumentLocked');
        } catch (\App\Domain\DocumentLocked) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(['document' => 'document.err_correction_not_allowed'], $this->errors(fn () => $this->svc->createCorrection($id)));
        $this->assertSame(['document' => 'document.err_payment_not_allowed'], $this->errors(fn () => $this->svc->addPayment($id, 100, $this->today())));
    }

    public function test_repo_status_writer_refuses_everything_but_issued_offers(): void
    {
        $repo = $this->repo($this->companyA);
        $inv = $this->draft([], null, 'invoice');
        $this->svc->issue($inv, $this->at());
        foreach ([$this->draft(), $inv, 999999] as $id) {
            try {
                $repo->updateOfferStatus($id, 'accepted', '2026-11-24 10:00:00');
                $this->fail('expected DocumentNotFound');
            } catch (DocumentNotFound) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNull($this->doc($inv)['offer_status']);
    }

    public function test_mark_offer_sent_only_moves_open_offers(): void
    {
        $open = $this->issued();
        $this->svc->markOfferSent($open);
        $this->assertSame('sent', $this->doc($open)['offer_status']);
        $accepted = $this->accepted();
        $this->svc->markOfferSent($accepted);
        $this->assertSame('accepted', $this->doc($accepted)['offer_status']);
        $rejected = $this->issued();
        $this->svc->setOfferStatus($rejected, 'rejected');
        $this->svc->markOfferSent($rejected);
        $this->assertSame('rejected', $this->doc($rejected)['offer_status']);
        $this->svc->markOfferSent($this->draft()); // a draft: nothing happens, no error
        $this->assertCount(3, $this->audit('document.offer_status'), 'open->sent, open->accepted, open->rejected; nothing for the no-ops');
    }

    public function test_totals_of_the_offer_match_a_recomputation(): void
    {
        $id = $this->issued(['lines' => [$this->line(['qty_milli' => 2500, 'unit_minor' => 45050, 'discount_bp' => 1250]), $this->line(['unit_minor' => 1200, 'vat_bp' => 700])]]);
        $d = $this->doc($id);
        $t = Totals::compute(array_map(fn (array $l): Line => new Line($l['qty_milli'], $l['unit_minor'], $l['discount_bp'], $l['vat_bp']), $d['lines']), false, 'EUR', null);
        $this->assertSame([$t->netMinor, $t->vatMinor, $t->grossMinor], [$d['net_minor'], $d['vat_minor'], $d['gross_minor']]);
    }
}
