<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\Request;
use App\Core\Response;
use App\Domain\DocumentService;
use App\Domain\NumberSequences;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Tenant\CompanyContext;
use App\Vies\ViesStatus;
use Tests\Pdf\PdfFixture;

/**
 * GET /documents/{id}/pdf (Task 17): headers, inline/attachment, sanitised file name, isolation,
 * drafts, other document types and the PDF links on the invoice pages.
 */
final class PdfRouteTest extends HttpTestCase
{
    private int $companyA;
    private int $companyB;
    private int $userId;
    private string $secret;
    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        // One storage dir for the whole class run: the font cache is built once.
        $this->app = $this->makeApp(['storage_path' => self::storage()]);
        $this->now = (new \DateTimeImmutable('2026-11-18 10:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp();
        $this->companyA = $this->company('Muster Service GmbH');
        $this->companyB = $this->company('Fremd AG');
        $user = $this->createUser();
        $this->userId = $user['id'];
        $this->secret = $user['secret'];
        $this->addMember($this->userId, $this->companyA);
        $this->customer = $this->customer($this->companyA, []);
    }

    private static function storage(): string
    {
        $dir = sys_get_temp_dir() . '/faktura-pdf-route-storage';
        if (!is_dir($dir . '/logos')) {
            mkdir($dir . '/logos', 0775, true);
        }
        return $dir;
    }

    private function company(string $name): int
    {
        $this->db->exec('INSERT INTO companies (name, address, vat_id, iban, bic) VALUES (?, ?, ?, ?, ?)',
            [$name, "Musterweg 1\n10115 Berlin", 'DE987654321', 'DE89370400440532013000', 'COBADEFFXXX']);
        return $this->db->lastId();
    }

    private function service(int $company): DocumentService
    {
        // The owner of $company acts here (company B gets its own member for its documents).
        $member = $this->db->one('SELECT user_id FROM company_users WHERE company_id = ? LIMIT 1', [$company]);
        if ($member === null) {
            $this->db->exec("INSERT INTO users (email, password_hash) VALUES (?, 'x')", ['b' . $company . '@example.com']);
            $this->addMember($this->db->lastId(), $company);
            $member = ['user_id' => $this->db->lastId()];
        }
        $ctx = CompanyContext::forUser($this->db, (int) $member['user_id'], $company);
        $clock = fn (): int => $this->now;
        return new DocumentService($this->db, $ctx, new NumberSequences($this->db, $ctx, $clock), new DocumentRepo($this->db, $ctx),
            new CustomerRepo($this->db, $ctx), $clock);
    }

    /** @param array<string, mixed> $data */
    private function customer(int $company, array $data, ?ViesStatus $vies = null): int
    {
        $this->service($company); // makes sure the company has a member
        $member = (int) $this->db->one('SELECT user_id FROM company_users WHERE company_id = ? LIMIT 1', [$company])['user_id'];
        $repo = new CustomerRepo($this->db, CompanyContext::forUser($this->db, $member, $company));
        $id = $repo->save($data + ['name' => 'Bauhaus Logistik GmbH', 'address' => "Hauptstraße 5\n80331 München", 'country' => 'DE',
            'vat_id' => 'DE123456789', 'is_company' => '1', 'payment_days' => '14', 'default_lang' => 'de', 'default_currency' => 'EUR']);
        if ($vies !== null) {
            $repo->recordVies($id, $vies, new \DateTimeImmutable('@' . $this->now), (string) $repo->find($id)['country'], (string) $repo->find($id)['vat_id']);
        }
        return $id;
    }

    private function draft(int $company, int $customer, string $type = 'invoice'): int
    {
        return $this->service($company)->createDraft($type, ['customer_id' => $customer, 'issue_date' => '2026-11-18',
            'lines' => PdfFixture::makietaLines()]);
    }

    private function issued(int $company, int $customer, string $type = 'invoice'): int
    {
        $id = $this->draft($company, $customer, $type);
        $this->service($company)->issue($id, new \DateTimeImmutable('2026-11-18 10:00', new \DateTimeZone('Europe/Berlin')));
        return $id;
    }

    private function getQ(string $path, array $query): Response
    {
        return $this->app->handle(new Request('GET', $path, query: $query, server: ['REMOTE_ADDR' => self::IP]));
    }

    private function requirePoppler(): void
    {
        if (!PdfFixture::hasPoppler()) {
            $this->markTestSkipped('pdftotext/pdfinfo (poppler-utils) are needed to read the rendered PDF.');
        }
    }

    private function t(string $key, string $lang = 'de', array $vars = []): string
    {
        return $this->app->translator()->t($key, $lang, $vars);
    }

    private function assertPdf(Response $r): void
    {
        $this->assertSame(200, $r->status, substr($r->body, 0, 500));
        $this->assertSame('application/pdf', $r->headers['Content-Type'] ?? null);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options'] ?? null);
        $this->assertSame('no-store', $r->headers['Cache-Control'] ?? null);
        $this->assertStringStartsWith('%PDF-', $r->body);
    }

    public function test_inline_pdf_with_headers(): void
    {
        $this->requirePoppler();
        $id = $this->issued($this->companyA, $this->customer);
        $this->loginFully($this->secret);
        $r = $this->get("/documents/$id/pdf");
        $this->assertPdf($r);
        $this->assertSame('inline; filename="RE-2026-001.pdf"', $r->headers['Content-Disposition'] ?? null);
        $t = PdfFixture::text($r->body);
        $this->assertStringContainsString('RE-2026-001', $t);
        $this->assertStringContainsString('Bauhaus Logistik GmbH', $t);
        $this->assertStringContainsString('1.206,60 €', $t);
    }

    public function test_download_attachment_and_sanitised_filename(): void
    {
        $id = $this->issued($this->companyA, $this->customer);
        $this->db->exec("UPDATE documents SET number = 'FV/26/11/0042' WHERE id = ?", [$id]);
        $this->loginFully($this->secret);
        $r = $this->getQ("/documents/$id/pdf", ['download' => '1']);
        $this->assertPdf($r);
        $this->assertSame('attachment; filename="FV_26_11_0042.pdf"', $r->headers['Content-Disposition'] ?? null);

        $this->db->exec("UPDATE documents SET number = ? WHERE id = ?", ["A\"B\\C/..\r\nX;Y", $id]);
        $r = $this->getQ("/documents/$id/pdf", ['download' => 'yes']);
        $this->assertSame('inline; filename="A_B_C_..__X_Y.pdf"', $r->headers['Content-Disposition'] ?? null, 'only download=1 switches');
    }

    public function test_other_company_and_unknown_ids_give_identical_404(): void
    {
        $foreign = $this->issued($this->companyB, $this->customer($this->companyB, []));
        $this->loginFully($this->secret);
        $r1 = $this->get("/documents/$foreign/pdf");
        $r2 = $this->get('/documents/999999/pdf');
        $this->assertSame(404, $r1->status);
        $this->assertSame(404, $r2->status);
        $this->assertStringStartsWith('text/html', $r1->headers['Content-Type'] ?? '');
        $this->assertStringNotContainsString('%PDF', $r1->body);
        $this->assertSame(
            str_replace("/documents/$foreign/pdf", '/X', $r1->body),
            str_replace('/documents/999999/pdf', '/X', $r2->body)
        );
    }

    public function test_draft_is_409_without_pdf(): void
    {
        $id = $this->draft($this->companyA, $this->customer);
        $this->loginFully($this->secret);
        $r = $this->get("/documents/$id/pdf");
        $this->assertSame(409, $r->status);
        $this->assertStringStartsWith('text/html', $r->headers['Content-Type'] ?? '');
        $this->assertStringContainsString(htmlspecialchars($this->t('pdf.err_draft'), ENT_QUOTES), $r->body);
        $this->assertStringNotContainsString('%PDF', $r->body);
        $this->assertArrayNotHasKey('Content-Disposition', $r->headers);
    }

    public function test_types_without_a_pdf_template_are_404(): void
    {
        // a synthetic type stored directly (every real type, dunning letters included, has a PDF now)
        $this->db->exec("INSERT INTO documents (company_id, type, status, number, lang, currency) VALUES (?, 'bogus_type', 'issued', 'X-1', 'de', 'EUR')",
            [$this->companyA]);
        $unsupported = $this->db->lastId();
        $this->loginFully($this->secret);
        $r = $this->get("/documents/$unsupported/pdf");
        $this->assertSame(404, $r->status);
        $this->assertSame(str_replace('/documents/999999/', "/documents/$unsupported/", $this->get('/documents/999999/pdf')->body), $r->body, 'identical to an unknown id');
    }

    public function test_unauthenticated_redirects_to_login_and_no_company_is_403(): void
    {
        $id = $this->issued($this->companyA, $this->customer);
        $this->assertRedirect('/login', $this->get("/documents/$id/pdf"));

        $other = $this->createUser('ohne@example.com');
        $this->loginFully($other['secret'], 'ohne@example.com');
        $this->assertSame(403, $this->get("/documents/$id/pdf")->status);
    }

    public function test_pdf_uses_the_document_language_not_the_ui_language(): void
    {
        $this->requirePoppler();
        $pl = $this->customer($this->companyA, ['name' => 'Kowalski Transport Sp. z o.o.', 'country' => 'PL', 'vat_id' => 'PL1234567890',
            'default_lang' => 'pl', 'default_currency' => 'PLN'], ViesStatus::Valid);
        $id = $this->issued($this->companyA, $pl);
        $this->loginFully($this->secret);
        $this->app->session()->set('lang', 'en');
        $r = $this->get("/documents/$id/pdf");
        $this->assertPdf($r);
        $t = PdfFixture::flat($r->body);
        $this->assertStringContainsString(mb_strtoupper($this->t('invoice.title', 'pl')), $t);
        $this->assertStringContainsString((string) preg_replace('/\s+/u', ' ', $this->t('invoice.reverse_charge_note', 'pl')), $t);
        $this->assertStringContainsString('RE-2026-001 · ' . $this->t('pdf.page', 'pl', ['page' => '1', 'pages' => '1']), $t);
    }

    public function test_pdf_links_on_issued_view_and_list_only(): void
    {
        $issued = $this->issued($this->companyA, $this->customer);
        $draft = $this->draft($this->companyA, $this->customer);
        $this->loginFully($this->secret);

        $view = $this->get("/invoices/$issued");
        $this->assertSame(200, $view->status);
        $this->assertStringContainsString('href="/documents/' . $issued . '/pdf"', $view->body);
        $this->assertStringContainsString('href="/documents/' . $issued . '/pdf?download=1"', $view->body);
        $this->assertStringContainsString(htmlspecialchars($this->t('pdf.open_label', 'de', ['number' => 'RE-2026-001']), ENT_QUOTES), $view->body);

        $editor = $this->get("/invoices/$draft");
        $this->assertSame(200, $editor->status);
        $this->assertStringNotContainsString('/documents/' . $draft . '/pdf', $editor->body);

        $list = $this->get('/invoices');
        $this->assertStringContainsString('href="/documents/' . $issued . '/pdf"', $list->body);
        $this->assertStringNotContainsString('/documents/' . $draft . '/pdf', $list->body);
    }
}
