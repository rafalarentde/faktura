<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Http\DocumentPresenter;
use App\Vies\ViesStatus;
use PHPUnit\Framework\TestCase;
use Tests\Pdf\PdfFixture;

/**
 * The one document view model (R28) used by the on-screen paper, the PDF and the mail body.
 * Issued documents are presented ONLY from their snapshots, stored lines and stored totals, in
 * the document language.
 */
final class DocumentPresenterTest extends TestCase
{
    private PdfFixture $f;
    private int $de;

    protected function setUp(): void
    {
        $this->f = new PdfFixture();
        $this->de = $this->f->customer($this->f->companyA);
    }

    protected function tearDown(): void
    {
        $this->f->cleanup();
    }

    /** @return array<string, mixed> */
    private function present(int $id): array
    {
        $doc = $this->f->repo($this->f->companyA)->find($id);
        $this->assertNotNull($doc);
        return self::plain($this->f->presenter($this->f->companyA)->issued($doc));
    }

    /** NBSP / narrow NBSP (number formatting) to plain spaces, recursively. */
    private static function plain(mixed $v): mixed
    {
        if (is_array($v)) {
            return array_map(self::plain(...), $v);
        }
        return is_string($v) ? str_replace(["\u{00A0}", "\u{202F}"], ' ', $v) : $v;
    }

    public function test_domestic_invoice_view_model(): void
    {
        $id = $this->f->issued($this->f->companyA, $this->de);
        $vm = $this->present($id);

        $this->assertSame('de', $vm['lang']);
        $this->assertSame('invoice', $vm['kind']);
        $this->assertSame('Rechnung', $vm['title']);
        $this->assertSame('RE-2026-001', $vm['number']);
        $this->assertSame('Rechnung RE-2026-001', $vm['doc_title']);
        $this->assertSame(['18.11.2026', '17.11.2026', '02.12.2026'], [$vm['issue_date'], $vm['service_date'], $vm['due_date']]);
        $this->assertSame('Zahlbar bis 02.12.2026 ohne Abzug.', $vm['payment']);
        $this->assertSame(['pos' => 1, 'name' => 'Beratung / Workshop', 'description' => '', 'qty' => '2', 'unit' => 'Std.',
            'price' => '450,00 €', 'discount' => '', 'vat' => '19 %', 'net' => '900,00 €'], $vm['rows'][0]);
        $this->assertSame('pauschal', $vm['rows'][1]['unit']);
        $this->assertSame('7 %', $vm['rows'][2]['vat']);
        $this->assertFalse($vm['has_discount']);
        $this->assertSame('1.020,00 €', $vm['net']);
        $this->assertSame([['label' => 'USt 19 %', 'amount' => '182,40 €'], ['label' => 'USt 7 %', 'amount' => '4,20 €']], $vm['vat']);
        $this->assertSame('1.206,60 €', $vm['gross']);
        $this->assertNull($vm['vat_eur']);
        $this->assertNull($vm['rate']);
        $this->assertNull($vm['note']);
        $this->assertNull($vm['related']);
        $this->assertSame(['Vielen Dank für deinen Auftrag.'], $vm['intro']);
        $this->assertSame(['Mit freundlichen Grüßen'], $vm['outro']);
        $this->assertSame(['name' => 'Bauhaus Logistik GmbH', 'address' => ['Hauptstraße 5', '80331 München'], 'country' => 'Deutschland',
            'vat_id' => 'DE123456789'], $vm['buyer']);
        $this->assertSame('Muster Service GmbH', $vm['seller']['name']);
        $this->assertSame(['Musterweg 1', '10115 Berlin'], $vm['seller']['address']);
        $this->assertSame(['DE987654321', '27/123/45678', 'DE89370400440532013000', 'COBADEFFXXX', 'Commerzbank Berlin'],
            [$vm['seller']['vat_id'], $vm['seller']['tax_number'], $vm['seller']['iban'], $vm['seller']['bic'], $vm['seller']['bank']]);
        $this->assertSame('Seite {page} von {pages}', $vm['labels']['page']);
    }

    public function test_labels_follow_the_document_language_not_the_ui(): void
    {
        $pl = $this->f->customer($this->f->companyA, ['name' => 'Łódź Żółć Sp. z o.o.', 'country' => 'PL', 'vat_id' => 'PL1234567890',
            'default_lang' => 'pl', 'default_currency' => 'PLN'], ViesStatus::Valid);
        $vm = $this->present($this->f->issued($this->f->companyA, $pl));
        $this->assertSame(['pl', 'Faktura', 'Polska'], [$vm['lang'], $vm['title'], $vm['buyer']['country']]);
        $this->assertSame('1 020,00 zł', $vm['net']);
        $this->assertSame($this->f->translator()->t('invoice.reverse_charge_note', 'pl'), $vm['note']);
        $this->assertSame([['label' => 'VAT 0 %', 'amount' => '0,00 zł']], $vm['vat']);
        $this->assertSame(['0 %', '0 %', '0 %'], array_column($vm['rows'], 'vat'));
        $this->assertNull($vm['vat_eur'], 'reverse charge: no VAT to convert');
        $this->assertNull($vm['rate'], 'reverse charge in PLN needs no rate');
        $this->assertSame('Strona {page} z {pages}', $vm['labels']['page']);

        $en = $this->f->customer($this->f->companyA, ['name' => 'Acme Ltd', 'country' => 'GB', 'vat_id' => '', 'default_lang' => 'en']);
        $vm = $this->present($this->f->issued($this->f->companyA, $en));
        $this->assertSame(['en', 'Invoice', '18/11/2026', 'United Kingdom', ''], [$vm['lang'], $vm['title'], $vm['issue_date'], $vm['buyer']['country'], $vm['buyer']['vat_id']]);
        $this->assertSame('€1,206.60', $vm['gross']);
    }

    public function test_foreign_currency_vat_in_eur_and_localised_rate(): void
    {
        $private = $this->f->customer($this->f->companyA, ['name' => 'Jan Kowalski', 'country' => 'PL', 'vat_id' => '', 'is_company' => '0',
            'default_lang' => 'de', 'default_currency' => 'PLN']);
        $vm = $this->present($this->f->issued($this->f->companyA, $private, ['currency' => 'PLN', 'eur_rate' => '4.2650']));
        $this->assertSame('1 EUR = 4,2650 PLN', $vm['rate']);
        // 186,60 zł VAT / 4.2650 = 43,75 €
        $this->assertSame('43,75 €', $vm['vat_eur']);
        $this->assertNull($vm['note']);

        $us = $this->f->customer($this->f->companyA, ['name' => 'Acme Inc', 'country' => 'US', 'vat_id' => '', 'default_lang' => 'en',
            'default_currency' => 'USD']);
        $vm = $this->present($this->f->issued($this->f->companyA, $us, ['currency' => 'USD', 'eur_rate' => '1.0870']));
        $this->assertSame('1 EUR = 1.0870 USD', $vm['rate']);
        $this->assertSame('US$1,206.60', $vm['gross']);
    }

    public function test_credit_note_is_negative_and_references_the_invoice(): void
    {
        $inv = $this->f->issued($this->f->companyA, $this->de);
        $cn = $this->f->creditNote($this->f->companyA, $inv, [PdfFixture::line(['name' => 'Gutschrift Beratung', 'unit_minor' => 6000])]);
        $vm = $this->present($cn);
        $this->assertSame(['credit_note', 'Rechnungskorrektur', 'KO-2026-001'], [$vm['kind'], $vm['title'], $vm['number']]);
        $this->assertSame('Korrektur zur Rechnung RE-2026-001 vom 18.11.2026', $vm['related']);
        $this->assertSame('60,00 €', $vm['rows'][0]['price'], 'lines stay positive');
        $this->assertSame(['-60,00 €', '-11,40 €', '-71,40 €'], [$vm['net'], $vm['vat'][0]['amount'], $vm['gross']]);
        $this->assertNull($vm['due_date']);
        $this->assertNull($vm['payment']);

        // The original cannot be found (e.g. a broken link): show nothing instead of failing.
        $this->f->db->exec('UPDATE documents SET related_document_id = 999999 WHERE id = ?', [$cn]);
        $this->assertNull($this->present($cn)['related']);
    }

    public function test_issued_documents_use_snapshots_and_stored_totals(): void
    {
        $id = $this->f->issued($this->f->companyA, $this->de);
        $this->f->db->exec("UPDATE customers SET name = 'Neuer Name AG', address = 'Neue Straße 9' WHERE id = ?", [$this->de]);
        $this->f->db->exec("UPDATE companies SET name = 'Umbenannt GmbH', address = 'Andere Allee 2', iban = 'DE02120300000000202051' WHERE id = ?", [$this->f->companyA]);
        // Stored totals are the source of the sums (they are written once, at issue).
        $this->f->db->exec('UPDATE documents SET net_minor = 102001, gross_minor = 120661 WHERE id = ?', [$id]);
        $vm = $this->present($id);
        $this->assertSame('Bauhaus Logistik GmbH', $vm['buyer']['name']);
        $this->assertSame(['Hauptstraße 5', '80331 München'], $vm['buyer']['address']);
        $this->assertSame('Muster Service GmbH', $vm['seller']['name']);
        $this->assertSame('DE89370400440532013000', $vm['seller']['iban']);
        $this->assertSame(['1.020,01 €', '1.206,61 €'], [$vm['net'], $vm['gross']]);
    }

    public function test_discount_column_and_empty_optional_fields(): void
    {
        $c = $this->f->customer($this->f->companyA, ['name' => 'Privat Person', 'address' => '', 'vat_id' => '', 'is_company' => '0']);
        $this->f->db->exec("UPDATE companies SET tax_number = NULL, bic = NULL, bank = NULL WHERE id = ?", [$this->f->companyA]);
        $id = $this->f->issued($this->f->companyA, $c, ['intro_text' => '', 'outro_text' => '', 'lines' => [
            PdfFixture::line(['name' => 'Rabattiert', 'description' => "Zeile 1\nZeile 2", 'discount_bp' => 1000]),
            PdfFixture::line(),
        ]]);
        $vm = $this->present($id);
        $this->assertTrue($vm['has_discount']);
        $this->assertSame(['10 %', ''], array_column($vm['rows'], 'discount'));
        $this->assertSame("Zeile 1\nZeile 2", $vm['rows'][0]['description']);
        $this->assertSame([], $vm['buyer']['address']);
        $this->assertSame('', $vm['buyer']['vat_id']);
        $this->assertSame(['', '', ''], [$vm['seller']['tax_number'], $vm['seller']['bic'], $vm['seller']['bank']]);
        $this->assertSame([[], []], [$vm['intro'], $vm['outro']]);
    }

    public function test_drafts_have_no_issued_view_model(): void
    {
        $id = $this->f->draft($this->f->companyA, $this->de);
        $doc = $this->f->repo($this->f->companyA)->find($id);
        $this->expectException(\LogicException::class);
        $this->f->presenter($this->f->companyA)->issued($doc);
    }

    public function test_class_lives_where_the_controller_and_pdf_share_it(): void
    {
        $this->assertTrue(class_exists(DocumentPresenter::class));
        $this->assertTrue(method_exists(DocumentPresenter::class, 'present'));
    }
}
