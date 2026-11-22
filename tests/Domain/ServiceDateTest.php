<?php
declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\TestCase;
use Tests\Pdf\PdfFixture;

/** I4: Leistungsdatum (§ 14 Abs. 4 Nr. 6 UStG) is never empty on an issued invoice or credit note. */
final class ServiceDateTest extends TestCase
{
    private PdfFixture $f;
    private int $customer;

    protected function setUp(): void
    {
        $this->f = new PdfFixture();
        $this->customer = $this->f->customer($this->f->companyA);
    }

    protected function tearDown(): void
    {
        $this->f->cleanup();
    }

    public function test_empty_service_date_defaults_to_the_issue_date(): void
    {
        $id = $this->f->issued($this->f->companyA, $this->customer, ['service_date' => null, 'issue_date' => '2026-11-18']);
        $doc = $this->f->repo($this->f->companyA)->find($id);
        $this->assertSame('2026-11-18', $doc['service_date']);
    }

    public function test_default_follows_an_empty_issue_date_too(): void
    {
        $id = $this->f->draft($this->f->companyA, $this->customer, ['service_date' => null, 'issue_date' => null]);
        $this->f->service($this->f->companyA)->issue($id, new \DateTimeImmutable('2026-11-24 10:00:00', new \DateTimeZone('UTC')), true);
        $doc = $this->f->repo($this->f->companyA)->find($id);
        $this->assertSame('2026-11-24', $doc['issue_date']);
        $this->assertSame('2026-11-24', $doc['service_date']);
    }

    public function test_an_explicit_service_date_is_kept(): void
    {
        $id = $this->f->issued($this->f->companyA, $this->customer);
        $this->assertSame('2026-11-17', $this->f->repo($this->f->companyA)->find($id)['service_date']);
    }

    public function test_credit_note_without_a_service_date_gets_one(): void
    {
        $inv = $this->f->issued($this->f->companyA, $this->customer);
        $this->f->db->exec('UPDATE documents SET service_date = NULL WHERE id = ?', [$inv]);
        $cn = $this->f->creditNote($this->f->companyA, $inv);
        $this->assertSame('2026-11-20', $this->f->repo($this->f->companyA)->find($cn)['service_date']);
    }

    public function test_offers_keep_no_service_date(): void
    {
        $svc = $this->f->service($this->f->companyA);
        $id = $svc->createDraft('offer', ['customer_id' => $this->customer, 'lines' => [PdfFixture::line()]]);
        $svc->issue($id, new \DateTimeImmutable('2026-11-24 10:00:00', new \DateTimeZone('UTC')), true);
        $this->assertNull($this->f->repo($this->f->companyA)->find($id)['service_date']);
    }

    public function test_presenter_flags_service_date_equal_to_issue_date(): void
    {
        $same = $this->f->issued($this->f->companyA, $this->customer, ['service_date' => null]);
        $other = $this->f->issued($this->f->companyA, $this->customer);
        $p = $this->f->presenter($this->f->companyA);
        $repo = $this->f->repo($this->f->companyA);
        $vm = $p->issued($repo->find($same));
        $this->assertTrue($vm['service_same']);
        $this->assertSame('Leistungsdatum entspricht Rechnungsdatum', $vm['labels']['service_same']);
        $this->assertFalse($p->issued($repo->find($other))['service_same']);
    }

    public function test_pdf_prints_the_line_only_when_the_dates_are_equal(): void
    {
        if (!PdfFixture::hasPoppler()) {
            $this->markTestSkipped('poppler is not installed');
        }
        $same = $this->f->issued($this->f->companyA, $this->customer, ['service_date' => null]);
        $other = $this->f->issued($this->f->companyA, $this->customer);
        $r = $this->f->renderer($this->f->companyA);
        $this->assertStringContainsString('Leistungsdatum entspricht Rechnungsdatum', PdfFixture::flat($r->render($same)));
        $text = PdfFixture::flat($r->render($other));
        $this->assertStringNotContainsString('entspricht Rechnungsdatum', $text);
        $this->assertStringContainsString('17.11.2026', $text);
    }

    public function test_the_line_exists_in_all_languages(): void
    {
        $t = $this->f->translator();
        $this->assertSame('Data wykonania usługi odpowiada dacie faktury', $t->t('invoice.service_same_as_issue', 'pl'));
        $this->assertSame('Date of supply is the invoice date', $t->t('invoice.service_same_as_issue', 'en'));
        $this->assertSame('Leistungsdatum entspricht Rechnungsdatum', $t->t('invoice.service_same_as_issue', 'de'));
    }
}
