<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\Request;
use App\Core\Response;
use Tests\Pdf\PdfFixture;
use Tests\Support\FakeResolver;
use Tests\Support\FakeTransport;

/**
 * Offer UI (Task 19): list, editor, issue, issued view with status buttons and conversion, PDF and
 * mail of offers, isolation/type separation, CSRF and escaping. The domain rules are tested in
 * Tests\Domain\OfferTest; invoice pages in InvoiceControllerTest.
 */
final class OfferControllerTest extends HttpTestCase
{
    private const BERLIN = 'Europe/Berlin';
    private const SMTP_PASSWORD = 'S3cr3t-Passw0rd!';

    private static string $storage = '';
    private FakeTransport $transport;
    private int $companyA;
    private int $companyB;
    private string $secret;
    private int $de;
    private int $plValid;
    private int $plUnverified;

    public static function setUpBeforeClass(): void
    {
        // Same stable directory as the PDF route tests: the Dompdf font cache is built once per process.
        self::$storage = sys_get_temp_dir() . '/faktura-pdf-route-storage';
        if (!is_dir(self::$storage . '/logos')) {
            mkdir(self::$storage . '/logos', 0775, true);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeTransport();
        $this->app = $this->makeApp(['storage_path' => self::$storage, 'mail_transport' => $this->transport, 'mail_host_resolver' => FakeResolver::publicHosts()]);
        $this->now = (new \DateTimeImmutable('2026-11-18 10:00', new \DateTimeZone(self::BERLIN)))->getTimestamp();
        $this->companyA = $this->company('Muster Service GmbH');
        $this->companyB = $this->company('Fremd AG');
        $user = $this->createUser();
        $this->secret = $user['secret'];
        $this->addMember($user['id'], $this->companyA);
        $this->de = $this->customer($this->companyA, ['name' => 'Bauhaus Logistik GmbH', 'address' => "Hauptstraße 5\n80331 München", 'vat_id' => 'DE123456789', 'email' => 'buchhaltung@bauhaus.example']);
        $this->plValid = $this->customer($this->companyA, ['name' => 'Kowalski Transport Sp. z o.o.', 'country' => 'PL', 'vat_id' => 'PL1234567890',
            'default_lang' => 'pl', 'default_currency' => 'PLN', 'payment_days' => 30, 'vies_status' => 'valid', 'vies_checked_at' => '2026-11-18 08:00:00']);
        $this->plUnverified = $this->customer($this->companyA, ['name' => 'Nowak Sp. z o.o.', 'country' => 'PL', 'vat_id' => 'PL9999999999',
            'default_lang' => 'pl', 'default_currency' => 'EUR', 'vies_status' => 'unavailable']);
    }

    // ---- helpers ----

    private function company(string $name, array $over = []): int
    {
        $row = $over + ['name' => $name, 'address' => "Musterweg 1\n10115 Berlin", 'vat_id' => 'DE987654321', 'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX',
            'intro_text' => 'Vielen Dank für deine Anfrage.', 'outro_text' => 'Mit freundlichen Grüßen', 'access_status' => 'active'];
        $this->db->exec('INSERT INTO companies (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
        return $this->db->lastId();
    }

    private function smtp(int $company): void
    {
        $this->db->exec('UPDATE companies SET smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass_enc = ?, smtp_from_name = ?, smtp_from_addr = ? WHERE id = ?',
            ['smtp.firma.example', 587, 'angebot@firma.example', $this->app->crypto()->encrypt(self::SMTP_PASSWORD), 'Muster Service', 'angebot@firma.example', $company]);
    }

    private function customer(int $company, array $data = []): int
    {
        $row = ['company_id' => $company] + $data + ['name' => 'Kunde GmbH', 'address' => "Kundenweg 1\n10115 Berlin", 'country' => 'DE', 'is_company' => 1,
            'default_lang' => 'de', 'default_currency' => 'EUR', 'payment_days' => 14];
        $this->db->exec('INSERT INTO customers (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
        return $this->db->lastId();
    }

    private function login(string $lang = 'de'): void
    {
        $this->loginFully($this->secret);
        if ($lang !== 'de') {
            $this->db->exec('UPDATE users SET ui_lang = ?', [$lang]);
            $this->app->session()->set('lang', $lang);
        }
    }

    /** @return array<string, string> */
    private function line(array $over = []): array
    {
        return $over + ['name' => 'Beratung', 'description' => '', 'qty' => '1', 'unit' => 'hour', 'price' => '100,00', 'discount' => '', 'vat_bp' => '1900', 'item_id' => ''];
    }

    /** @return list<array<string, string>> 2 x 450,00 @19, 1 x 60,00 @19, 5 x 12,00 @7 = net 1.020,00, gross 1.206,60 */
    private function makietaLines(): array
    {
        return [$this->line(['name' => 'Beratung / Workshop', 'qty' => '2', 'price' => '450,00']), $this->line(['name' => 'Anfahrtspauschale', 'unit' => 'flat', 'price' => '60,00']),
            $this->line(['name' => 'Schulungsunterlagen', 'qty' => '5', 'unit' => 'piece', 'price' => '12,00', 'vat_bp' => '700'])];
    }

    /** @return array<string, mixed> */
    private function form(int $customer, array $over = [], ?array $lines = null): array
    {
        return $over + ['customer_id' => (string) $customer, 'lang' => 'de', 'currency' => 'EUR', 'eur_rate' => '', 'issue_date' => '2026-11-18',
            'valid_until' => '2026-12-18', 'intro_text' => 'Hallo', 'outro_text' => 'Danke', 'notes' => '', 'lines' => $lines ?? $this->makietaLines()];
    }

    private function createDraft(int $customer = 0, array $over = [], ?array $lines = null): int
    {
        $r = $this->post('/offers', $this->form($customer ?: $this->de, $over, $lines));
        $this->assertSame(303, $r->status, substr($r->body, 0, 2000));
        $this->assertMatchesRegularExpression('#^/offers/\d+$#', $r->headers['Location']);
        return (int) substr($r->headers['Location'], strlen('/offers/'));
    }

    /** @return array<string, mixed> the editor fields for a stored draft */
    private function formFromDb(int $id): array
    {
        $d = $this->doc($id);
        $lines = [];
        foreach ($this->db->all('SELECT * FROM document_lines WHERE document_id = ? ORDER BY position', [$id]) as $l) {
            $lines[] = ['name' => $l['name'], 'description' => (string) $l['description'], 'qty' => (string) ($l['qty_milli'] / 1000), 'unit' => $l['unit'],
                'price' => number_format($l['unit_minor'] / 100, 2, ',', ''), 'discount' => '', 'vat_bp' => (string) $l['vat_bp'], 'item_id' => (string) $l['item_id']];
        }
        return ['customer_id' => (string) $d['customer_id'], 'lang' => $d['lang'], 'currency' => $d['currency'], 'eur_rate' => (string) $d['eur_rate'],
            'issue_date' => (string) $d['issue_date'], 'valid_until' => (string) $d['valid_until'], 'intro_text' => (string) $d['intro_text'],
            'outro_text' => (string) $d['outro_text'], 'notes' => (string) $d['notes'], 'lines' => $lines];
    }

    private function issued(int $customer = 0, array $over = []): int
    {
        $id = $this->createDraft($customer, $over);
        $r = $this->post("/offers/$id/issue", $this->formFromDb($id));
        $this->assertSame(303, $r->status, substr($r->body, 0, 3000));
        return $id;
    }

    private function accepted(int $customer = 0): int
    {
        $id = $this->issued($customer);
        $this->assertSame(303, $this->post("/offers/$id/status", ['status' => 'accepted'])->status);
        return $id;
    }

    /** @return array<string, mixed> */
    private function doc(int $id): array
    {
        return $this->db->one('SELECT * FROM documents WHERE id = ?', [$id]);
    }

    private function docCount(string $where = '1=1'): int
    {
        return (int) $this->db->one("SELECT COUNT(*) AS c FROM documents WHERE $where")['c'];
    }

    private function plain(string $html): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $html);
    }

    private function getQ(string $path, array $query): Response
    {
        return $this->app->handle(new Request('GET', $path, query: $query, server: ['REMOTE_ADDR' => self::IP]));
    }

    /** @param array<string, mixed> $data */
    private function recalc(string $path, array $data): Response
    {
        return $this->app->handle(new Request('POST', $path, post: $data, server: ['REMOTE_ADDR' => self::IP, 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->app->csrf()->token()]));
    }

    /** @return list<array{0: string, 1: string}> every /offers route from config/routes.php, {id} replaced */
    private function offerRoutes(string $id): array
    {
        return $this->routes('/offers', $id);
    }

    /** @return list<array{0: string, 1: string}> */
    private function routes(string $prefix, string $id): array
    {
        $collector = new class (new \FastRoute\RouteParser\Std(), new \FastRoute\DataGenerator\GroupCountBased()) extends \FastRoute\RouteCollector {
            /** @var list<array{0: string, 1: string}> */
            public array $seen = [];

            public function addRoute($httpMethod, $route, $handler): void
            {
                foreach ((array) $httpMethod as $m) {
                    $this->seen[] = [$m, $route];
                }
                parent::addRoute($httpMethod, $route, $handler);
            }
        };
        (require __DIR__ . '/../../config/routes.php')($this->app, $collector);
        $out = [];
        foreach ($collector->seen as [$method, $pattern]) {
            if (str_starts_with($pattern, $prefix)) {
                $out[] = [$method, (string) preg_replace('/\{\w+(?::[^}]*)?\}/', $id, $pattern)];
            }
        }
        return $out;
    }

    // ---- brief flow ----

    public function test_create_draft_stores_valid_until_and_never_a_due_or_service_date(): void
    {
        $this->login();
        $id = $this->createDraft(0, ['due_date' => '2026-12-31', 'service_date' => '2026-11-01', 'valid_until' => '2026-12-20']);
        $d = $this->doc($id);
        $this->assertSame(['offer', 'draft', null, '2026-12-20', null, null, null], [$d['type'], $d['status'], $d['number'], $d['valid_until'], $d['due_date'], $d['service_date'], $d['offer_status']]);
        $page = $this->get("/offers/$id");
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('name="valid_until" value="2026-12-20"', $page->body);
        $this->assertStringContainsString('action="/offers/' . $id . '/save"', $page->body);
        $this->assertStringContainsString('data-recalc="/offers/recalc"', $page->body);
        foreach (['name="due_date"', 'name="service_date"', 'name="confirm_vat"'] as $absent) {
            $this->assertStringNotContainsString($absent, $page->body, $absent);
        }
        $this->assertStringContainsString('Angebotsentwurf', $page->body);
        $this->assertStringContainsString('nächste Nummer: AN-2026-001', $page->body, 'the offer sequence, not the invoice one');
    }

    public function test_new_offer_defaults_valid_until_to_thirty_days(): void
    {
        $this->login();
        $step1 = $this->get('/offers/new');
        $this->assertSame(200, $step1->status);
        $this->assertStringContainsString('action="/offers/new"', $step1->body);
        $this->assertStringContainsString('Bauhaus Logistik GmbH', $step1->body);
        $r = $this->getQ('/offers/new', ['customer_id' => (string) $this->de]);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('name="issue_date" value="2026-11-18"', $r->body);
        $this->assertStringContainsString('name="valid_until" value="2026-12-18"', $r->body);
        $this->assertStringContainsString('action="/offers"', $r->body);
        $bad = $this->getQ('/offers/new', ['customer_id' => (string) ($this->plValid + 99999)]);
        $this->assertStringContainsString('role="alert"', $bad->body);
    }

    public function test_valid_until_validation_errors_are_shown(): void
    {
        $this->login();
        $r = $this->post('/offers', $this->form($this->de, ['valid_until' => '2026-11-01']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString($this->app->t('document.err_valid_until_before_issue'), $r->body);
        $this->assertStringContainsString('aria-invalid="true"', $r->body);
        $this->assertSame(0, $this->docCount());
        $r = $this->post('/offers', $this->form($this->de, ['valid_until' => '2026-02-30']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString($this->app->t('document.err_date'), $r->body);
    }

    public function test_issue_view_and_actions(): void
    {
        $this->login();
        $id = $this->createDraft(0, ['notes' => 'intern']);
        $r = $this->post("/offers/$id/issue", $this->formFromDb($id));
        $this->assertSame(303, $r->status);
        $this->assertSame("/offers/$id", $r->headers['Location']);
        $d = $this->doc($id);
        $this->assertSame(['AN-2026-001', 'issued', 'open', '2026-12-18'], [$d['number'], $d['status'], $d['offer_status'], $d['valid_until']]);
        $this->assertNull($d['due_date']);
        $page = $this->get("/offers/$id");
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Ausgestellt als AN-2026-001.', $page->body);
        $this->assertStringContainsString('AN-2026-001', $page->body);
        $this->assertStringContainsString('Angebot', $page->body);
        $this->assertStringContainsString('Gültig bis', $page->body);
        $this->assertStringContainsString('18.12.2026', $page->body);
        $this->assertStringContainsString('data-block="document_actions"', $page->body);
        $this->assertStringContainsString("/documents/$id/pdf", $page->body);
        $this->assertStringContainsString("/documents/$id/send", $page->body);
        $this->assertStringContainsString('1.206,60', $this->plain($page->body));
        foreach (['Fällig', 'Zahlungen', 'Zahlung erfassen', 'action="/offers/' . $id . '/save"', 'action="/offers/' . $id . '/delete"', 'IBAN', 'DE89370400440532013000'] as $absent) {
            $this->assertStringNotContainsString($absent, $page->body, $absent);
        }
        $this->assertStringContainsString('chip-sent', $page->body);
        $this->assertStringNotContainsString('/convert"', $page->body, 'no convert button before the offer is accepted');
        $this->assertStringContainsString('Interne Notiz', $page->body);
    }

    public function test_status_buttons_and_flow(): void
    {
        $this->login();
        $id = $this->issued();
        $page = $this->get("/offers/$id")->body;
        foreach (['sent', 'accepted', 'rejected'] as $s) {
            $this->assertStringContainsString('name="status" value="' . $s . '"', $page, $s);
        }
        $this->assertStringNotContainsString('name="status" value="open"', $page, 'the current status has no button');
        $this->assertGreaterThanOrEqual(3, substr_count($page, 'name="_csrf"'), 'every status form carries the token');

        $r = $this->post("/offers/$id/status", ['status' => 'sent']);
        $this->assertSame(303, $r->status);
        $this->assertSame("/offers/$id", $r->headers['Location']);
        $this->assertSame('sent', $this->doc($id)['offer_status']);
        $this->assertStringContainsString('Status gespeichert.', $this->get("/offers/$id")->body);
        $this->assertStringContainsString('Gesendet', $this->get("/offers/$id")->body);

        $this->post("/offers/$id/status", ['status' => 'accepted']);
        $accepted = $this->get("/offers/$id")->body;
        $this->assertSame('accepted', $this->doc($id)['offer_status']);
        $this->assertStringContainsString('chip-ok', $accepted);
        $this->assertStringContainsString('action="/offers/' . $id . '/convert"', $accepted, 'convert button for accepted offers');
        $this->assertStringContainsString('Wieder öffnen', $accepted);

        $this->post("/offers/$id/status", ['status' => 'open']);
        $this->assertSame('open', $this->doc($id)['offer_status']);
        $this->post("/offers/$id/status", ['status' => 'rejected']);
        $this->assertSame('rejected', $this->doc($id)['offer_status']);
        $this->assertStringContainsString('chip-late', $this->get("/offers/$id")->body);
        $this->assertStringNotContainsString('/convert"', $this->get("/offers/$id")->body);
        $audit = $this->db->all("SELECT detail FROM audit_log WHERE action = 'document.offer_status' ORDER BY id");
        $this->assertCount(4, $audit);
    }

    public function test_invalid_status_values_and_drafts_change_nothing(): void
    {
        $this->login();
        $id = $this->issued();
        foreach (['expired', 'draft', 'issued', '', 'ACCEPTED', 'accepted ', '<script>'] as $bad) {
            $r = $this->post("/offers/$id/status", ['status' => $bad]);
            $this->assertSame(303, $r->status, $bad);
            $this->assertSame('open', $this->doc($id)['offer_status'], $bad);
        }
        $this->assertStringContainsString($this->app->t('document.err_offer_status'), $this->get("/offers/$id")->body);
        $r = $this->post("/offers/$id/status", ['status' => ['accepted']]);
        $this->assertSame('open', $this->doc($id)['offer_status']);
        $draft = $this->createDraft();
        $this->post("/offers/$draft/status", ['status' => 'accepted']);
        $this->assertNull($this->doc($draft)['offer_status']);
        $this->assertStringContainsString($this->app->t('document.err_offer_draft_status'), $this->get('/offers')->body . $this->get("/offers/$draft")->body);
        $this->post("/offers/$id/status", ['status' => 'accepted', 'return' => 'list']);
        $this->assertSame('accepted', $this->doc($id)['offer_status']);
    }

    public function test_status_redirect_back_to_the_list(): void
    {
        $this->login();
        $id = $this->issued();
        $r = $this->post("/offers/$id/status", ['status' => 'accepted', 'return' => 'list']);
        $this->assertSame('/offers', $r->headers['Location']);
        $r = $this->post("/offers/$id/status", ['status' => 'rejected', 'return' => 'https://evil.example/']);
        $this->assertSame("/offers/$id", $r->headers['Location'], 'only the value "list" is honoured');
    }

    public function test_convert_flow_and_double_click(): void
    {
        $this->login();
        $id = $this->accepted();
        $r = $this->post("/offers/$id/convert");
        $this->assertSame(303, $r->status);
        $this->assertMatchesRegularExpression('#^/invoices/\d+$#', $r->headers['Location']);
        $invoice = (int) substr($r->headers['Location'], strlen('/invoices/'));
        $d = $this->doc($invoice);
        $this->assertSame(['invoice', 'draft', null, $id], [$d['type'], $d['status'], $d['number'], $d['related_document_id']]);
        $this->assertSame(120660, (int) $d['gross_minor']);
        $this->assertSame(3, (int) $this->db->one('SELECT COUNT(*) AS c FROM document_lines WHERE document_id = ?', [$invoice])['c']);

        $editor = $this->get($r->headers['Location']);
        $this->assertSame(200, $editor->status);
        $this->assertStringContainsString('Rechnungsentwurf', $editor->body);
        $this->assertStringContainsString('data-source-offer', $editor->body);
        $this->assertStringContainsString('href="/offers/' . $id . '"', $editor->body);
        $this->assertStringContainsString('AN-2026-001', $editor->body);
        $this->assertStringContainsString('Vielen Dank für deine Anfrage.', $editor->body, 'company default text, not the offer wording');
        $this->assertStringNotContainsString('value="Hallo"', $editor->body);
        $this->assertStringContainsString('Rechnungsentwurf zum Angebot AN-2026-001 angelegt.', $editor->body);

        // double click: the same draft, no second invoice
        $again = $this->post("/offers/$id/convert");
        $this->assertSame("/invoices/$invoice", $again->headers['Location']);
        $this->assertSame(1, $this->docCount("type = 'invoice'"));

        // the offer lists the linked invoice and keeps its status fixed
        $view = $this->get("/offers/$id")->body;
        $this->assertStringContainsString('href="/invoices/' . $invoice . '"', $view);
        $this->assertStringNotContainsString('data-already-invoiced', $view, 'only a draft so far');
        $this->assertStringContainsString($this->app->t('offer.locked_accepted'), $view);
        $this->assertStringNotContainsString('name="status" value="open"', $view);
        $this->post("/offers/$id/status", ['status' => 'open']);
        $this->assertSame('accepted', $this->doc($id)['offer_status']);
        $this->assertStringContainsString($this->app->t('document.err_offer_converted'), $this->get("/offers/$id")->body);

        // issue the invoice: the offer shows the warning and a further draft can be created
        $form = ['customer_id' => (string) $this->de, 'lang' => 'de', 'currency' => 'EUR', 'eur_rate' => '', 'issue_date' => '2026-11-18', 'service_date' => '2026-11-18',
            'due_date' => '2026-12-02', 'intro_text' => 'x', 'outro_text' => 'y', 'notes' => '', 'lines' => $this->makietaLines()];
        $this->assertSame(303, $this->post("/invoices/$invoice/issue", $form)->status);
        $issuedInvoice = $this->get("/invoices/$invoice")->body;
        $this->assertStringContainsString('data-related-offer', $issuedInvoice);
        $this->assertStringContainsString('href="/offers/' . $id . '"', $issuedInvoice);
        $view = $this->get("/offers/$id")->body;
        $this->assertStringContainsString('data-already-invoiced', $view);
        $this->assertStringContainsString('RE-2026-001', $view);
        $second = $this->post("/offers/$id/convert");
        $this->assertNotSame("/invoices/$invoice", $second->headers['Location']);
        $this->assertSame(2, $this->docCount("type = 'invoice'"));
    }

    public function test_convert_refused_unless_accepted(): void
    {
        $this->login();
        $open = $this->issued();
        $draft = $this->createDraft();
        foreach ([$open, $draft] as $id) {
            $r = $this->post("/offers/$id/convert");
            $this->assertSame(303, $r->status);
            $this->assertSame("/offers/$id", $r->headers['Location']);
            $this->assertStringContainsString($this->app->t('document.err_offer_not_accepted'), $this->get("/offers/$id")->body);
        }
        $this->assertSame(0, $this->docCount("type = 'invoice'"));
        $this->post("/offers/$open/status", ['status' => 'rejected']);
        $this->post("/offers/$open/convert");
        $this->assertSame(0, $this->docCount("type = 'invoice'"));
    }

    // ---- list ----

    public function test_list_chips_filters_and_actions(): void
    {
        $this->login();
        $draft = $this->createDraft();
        $open = $this->issued();
        $sent = $this->issued();
        $this->post("/offers/$sent/status", ['status' => 'sent']);
        $accepted = $this->accepted();
        $rejected = $this->issued();
        $this->post("/offers/$rejected/status", ['status' => 'rejected']);
        $expired = $this->issued(0, ['issue_date' => '2026-11-01', 'valid_until' => '2026-11-17']);
        $todayOk = $this->issued(0, ['issue_date' => '2026-11-01', 'valid_until' => '2026-11-18']);
        $invoiced = $this->accepted();
        $this->post("/offers/$invoiced/convert");

        $list = $this->get('/offers');
        $this->assertSame(200, $list->status);
        $b = $list->body;
        foreach (['Entwurf', 'Offen', 'Gesendet', 'Angenommen', 'Abgelehnt', 'Abgelaufen', 'Berechnet'] as $label) {
            $this->assertStringContainsString($label, $b, $label);
        }
        foreach (['chip-draft', 'chip-sent', 'chip-ok', 'chip-late', 'chip-soon', 'chip-outline'] as $cls) {
            $this->assertStringContainsString($cls, $b, $cls);
        }
        $this->assertStringContainsString('data-status="expired"', $b);
        $this->assertSame(1, substr_count($b, 'data-status="expired"'), 'valid until today is not expired');
        $this->assertSame(1, substr_count($b, 'data-invoiced'));
        $this->assertStringContainsString('action="/offers/' . $open . '/status"', $b, 'quick accept');
        $this->assertStringContainsString('action="/offers/' . $accepted . '/convert"', $b);
        $this->assertStringNotContainsString('action="/offers/' . $rejected . '/convert"', $b);
        $this->assertStringNotContainsString('action="/offers/' . $rejected . '/status"', $b);
        $this->assertStringContainsString('Gültig bis', $b);

        $ids = fn (string $q): array => array_map('intval', $this->allMatches('#href="/offers/(\d+)"#', $this->getQ('/offers', ['status' => $q])->body));
        $this->assertEquals([$draft], array_unique($ids('draft')));
        $this->assertEquals([$expired], array_unique($ids('expired')));
        $this->assertEqualsCanonicalizing([$open, $todayOk], array_unique($ids('open')));
        $this->assertEquals([$sent], array_unique($ids('sent')));
        $this->assertEqualsCanonicalizing([$accepted, $invoiced], array_unique($ids('accepted')));
        $this->assertEquals([$rejected], array_unique($ids('rejected')));
        $unknown = $this->getQ('/offers', ['status' => 'bogus']);
        $this->assertSame(200, $unknown->status);
        $this->assertGreaterThan(5, count(array_unique($this->allMatches('#href="/offers/(\d+)"#', $unknown->body))), 'unknown status = no filter');
        $this->assertSame(200, $this->getQ('/offers', ['status' => ['x'], 'q' => ['y'], 'page' => ['z']])->status);
    }

    /** @return list<string> */
    private function allMatches(string $re, string $s): array
    {
        preg_match_all($re, $s, $m);
        return $m[1];
    }

    public function test_list_search_special_characters_and_empty_states(): void
    {
        $this->login();
        $empty = $this->get('/offers');
        $this->assertStringContainsString('Noch keine Angebote', $empty->body);
        $this->assertStringContainsString('/offers/new', $empty->body);
        $weird = $this->customer($this->companyA, ['name' => "Müller & Söhne <b>100%</b> _x_ 'q'"]);
        $id = $this->issued($weird);
        $this->issued();
        foreach (['Müller', 'müller', 'MÜLLER', '%', '_', "'", '<b>', '&', 'AN-2026-001'] as $q) {
            $r = $this->getQ('/offers', ['q' => $q]);
            $this->assertSame(200, $r->status, $q);
            $this->assertStringNotContainsString('<b>100', $r->body, 'escaped');
        }
        $r = $this->getQ('/offers', ['q' => 'Müller']);
        $this->assertSame([$id], array_map('intval', array_unique($this->allMatches('#href="/offers/(\d+)"#', $r->body))));
        $this->assertStringContainsString('Müller &amp; Söhne &lt;b&gt;100%&lt;/b&gt;', $r->body);
        $none = $this->getQ('/offers', ['q' => 'gibtesnicht']);
        $this->assertStringContainsString($this->app->t('invoice.list_no_results'), $none->body);
        $this->assertStringContainsString('/offers"', $none->body);
    }

    public function test_list_pagination(): void
    {
        $this->login();
        for ($i = 1; $i <= 27; $i++) {
            $this->db->exec("INSERT INTO documents (company_id, type, status, number, buyer_name, issue_date, valid_until, offer_status, customer_id) VALUES (?, 'offer', 'issued', ?, 'Serie', ?, '2027-01-01', 'open', ?)",
                [$this->companyA, sprintf('AN-X-%03d', $i), sprintf('2026-10-%02d', min($i, 28)), $this->de]);
        }
        $p1 = $this->get('/offers');
        $this->assertCount(25, array_unique($this->allMatches('#href="/offers/(\d+)"#', $p1->body)));
        $this->assertStringContainsString('Seite 1 von 2', $p1->body);
        $this->assertStringContainsString('page=2', $p1->body);
        $p2 = $this->getQ('/offers', ['page' => '2', 'status' => 'open']);
        $this->assertCount(2, array_unique($this->allMatches('#href="/offers/(\d+)"#', $p2->body)));
        $this->assertStringContainsString('status=open', $p2->body);
        $this->assertStringContainsString('Seite 2 von 2', $this->getQ('/offers', ['page' => '99'])->body, 'clamped');
        $this->assertStringContainsString('Seite 1 von 2', $this->getQ('/offers', ['page' => '-3'])->body);
    }

    // ---- isolation and type separation ----

    public function test_foreign_offer_ids_are_404_identical_to_unknown_on_every_route(): void
    {
        $foreignCustomer = $this->customer($this->companyB, ['name' => 'Geheim GmbH']);
        $this->db->exec("INSERT INTO documents (company_id, type, status, number, customer_id, buyer_name, issue_date, valid_until, offer_status, lang, currency, seller_json, buyer_json) VALUES (?, 'offer', 'issued', 'AN-9', ?, 'Geheim GmbH', '2026-11-01', '2026-12-01', 'accepted', 'de', 'EUR', '{}', '{}')", [$this->companyB, $foreignCustomer]);
        $foreign = $this->db->lastId();
        $this->login();
        $before = $this->doc($foreign);
        $send = fn (string $method, string $path): Response => $method === 'GET' ? $this->get($path) : $this->post($path, $this->form($this->de) + ['status' => 'rejected']);
        $paths = array_merge($this->offerRoutes((string) $foreign), [['GET', "/documents/$foreign/pdf"], ['GET', "/documents/$foreign/send"], ['POST', "/documents/$foreign/send"]]);
        $checked = 0;
        foreach ($paths as [$method, $path]) {
            if (str_contains($path, '/recalc') || $path === '/offers' || $path === '/offers/new') {
                continue;
            }
            $r = $send($method, $path);
            $unknown = $send($method, str_replace((string) $foreign, '999999', $path));
            $strip = fn (string $b): string => (string) preg_replace('/name="back" value="[^"]*"|name="_csrf" value="[^"]*"/', '', $b);
            $this->assertSame(404, $r->status, "$method $path");
            $this->assertSame($strip(str_replace('999999', (string) $foreign, $unknown->body)), $strip($r->body), "$method $path identical to an unknown id");
            $this->assertStringNotContainsString('Geheim', $r->body);
            $checked++;
        }
        $this->assertGreaterThanOrEqual(9, $checked);
        $this->assertSame($before, $this->doc($foreign));
        $this->assertSame(404, $this->recalc('/offers/recalc', $this->form($this->de) + ['document_id' => (string) $foreign])->status);
        $this->assertSame(0, $this->docCount("company_id = {$this->companyA}"));
        $this->assertSame(0, $this->docCount("type = 'invoice'"));
        $r = $this->post('/offers', $this->form($foreignCustomer));
        $this->assertSame(422, $r->status, 'a foreign customer never creates a draft');
    }

    public function test_invoice_ids_on_offer_routes_and_offer_ids_on_invoice_routes_are_404(): void
    {
        $this->login();
        $offerDraft = $this->createDraft();
        $offer = $this->accepted();
        $r = $this->post('/invoices', ['customer_id' => (string) $this->de, 'lang' => 'de', 'currency' => 'EUR', 'issue_date' => '2026-11-18', 'service_date' => '2026-11-18',
            'due_date' => '2026-12-02', 'lines' => $this->makietaLines()]);
        $invoice = (int) substr($r->headers['Location'], strlen('/invoices/'));
        $beforeInvoice = $this->doc($invoice);
        $beforeOffer = $this->doc($offer);
        foreach ($this->offerRoutes((string) $invoice) as [$method, $path]) {
            if (in_array($path, ['/offers', '/offers/new'], true) || str_contains($path, '/recalc')) {
                continue;
            }
            $r = $method === 'GET' ? $this->get($path) : $this->post($path, $this->form($this->de) + ['status' => 'rejected']);
            $this->assertSame(404, $r->status, "$method $path (an invoice id)");
        }
        foreach ([$offer, $offerDraft] as $id) {
            foreach ($this->routes('/invoices', (string) $id) as [$method, $path]) {
                if (in_array($path, ['/invoices', '/invoices/new'], true) || str_contains($path, '/recalc')) {
                    continue;
                }
                $r = $method === 'GET' ? $this->get($path) : $this->post($path, $this->form($this->de) + ['amount' => '1,00', 'paid_on' => '2026-11-18']);
                $this->assertSame(404, $r->status, "$method $path (an offer id)");
            }
        }
        // recalc takes a document only of its own type
        $this->assertSame(404, $this->recalc('/offers/recalc', $this->form($this->de) + ['document_id' => (string) $invoice])->status);
        $this->assertSame(404, $this->recalc('/invoices/recalc', $this->form($this->de) + ['document_id' => (string) $offerDraft])->status);
        $this->assertSame(200, $this->recalc('/offers/recalc', $this->form($this->de) + ['document_id' => (string) $offerDraft])->status);
        $this->assertSame($beforeInvoice, $this->doc($invoice));
        $this->assertSame($beforeOffer, $this->doc($offer));
        $this->assertSame('draft', $this->doc($offerDraft)['status']);
    }

    // ---- routes, auth, CSRF ----

    public function test_unauthenticated_redirects_and_no_company_is_403_on_every_route(): void
    {
        $routes = $this->offerRoutes('1');
        $this->assertGreaterThanOrEqual(10, count($routes), 'offer routes not found');
        foreach ($routes as [$method, $path]) {
            $this->assertRedirect('/login', $method === 'GET' ? $this->get($path) : $this->post($path, []));
        }
        $other = $this->createUser('solo@example.pl');
        $this->loginFully($other['secret'], 'solo@example.pl');
        foreach ($routes as [$method, $path]) {
            $this->assertSame(403, ($method === 'GET' ? $this->get($path) : $this->post($path, []))->status, "$method $path");
        }
    }

    public function test_every_post_route_without_csrf_is_419(): void
    {
        $this->login();
        $id = $this->accepted();
        $draft = $this->createDraft();
        $before = [$this->docCount(), $this->doc($id), $this->doc($draft)];
        foreach ([$id, $draft] as $target) {
            foreach ($this->offerRoutes((string) $target) as [$method, $path]) {
                if ($method === 'POST') {
                    $this->assertSame(419, $this->post($path, $this->form($this->de) + ['status' => 'rejected'], csrf: false)->status, $path);
                }
            }
        }
        $this->assertSame($before, [$this->docCount(), $this->doc($id), $this->doc($draft)]);
        $this->assertSame(419, $this->app->handle(new Request('POST', '/offers/recalc', post: $this->form($this->de), server: ['REMOTE_ADDR' => self::IP]))->status);
    }

    public function test_mass_assignment_is_ignored(): void
    {
        $this->login();
        $id = $this->createDraft(0, ['company_id' => (string) $this->companyB, 'id' => '777', 'type' => 'invoice', 'status' => 'issued', 'number' => 'X-1',
            'offer_status' => 'accepted', 'related_document_id' => '1', 'reverse_charge' => '1', 'net_minor' => '1', 'gross_minor' => '1', 'vat_note_key' => 'x']);
        $d = $this->doc($id);
        $this->assertSame(['offer', 'draft', null, null, null, $this->companyA, 0], [$d['type'], $d['status'], $d['number'], $d['offer_status'], $d['related_document_id'], (int) $d['company_id'], (int) $d['reverse_charge']]);
        $this->assertNotSame(777, (int) $d['id']);
        $this->assertSame(120660, (int) $d['gross_minor']);
        $form = $this->formFromDb($id) + ['status' => 'issued', 'offer_status' => 'accepted', 'type' => 'invoice', 'related_document_id' => '1'];
        $this->post("/offers/$id/save", $form);
        $d = $this->doc($id);
        $this->assertSame(['offer', 'draft', null, null], [$d['type'], $d['status'], $d['number'], $d['offer_status']]);
        $this->post("/offers/$id/issue", $form);
        $d = $this->doc($id);
        $this->assertSame(['offer', 'issued', 'open', null], [$d['type'], $d['status'], $d['offer_status'], $d['related_document_id']]);
        // an invoice posted with valid_until never stores it
        $r = $this->post('/invoices', ['customer_id' => (string) $this->de, 'lang' => 'de', 'currency' => 'EUR', 'issue_date' => '2026-11-18', 'service_date' => '2026-11-18',
            'due_date' => '2026-12-02', 'valid_until' => '2027-01-01', 'lines' => $this->makietaLines()]);
        $this->assertSame(303, $r->status);
        $inv = $this->doc((int) substr($r->headers['Location'], strlen('/invoices/')));
        $this->assertNull($inv['valid_until']);
        $this->assertNull($inv['offer_status']);
    }

    // ---- escaping ----

    public function test_xss_matrix_is_escaped(): void
    {
        $evil = '<script>alert(1)</script> "&\'';
        $c = $this->customer($this->companyA, ['name' => 'Evil ' . $evil, 'address' => "Str. <i>1</i>\n12345 <b>X</b>"]);
        $this->login();
        $id = $this->createDraft($c, ['intro_text' => $evil, 'outro_text' => $evil, 'notes' => $evil], [$this->line(['name' => $evil, 'description' => $evil])]);
        $pages = ['editor' => $this->get("/offers/$id")];
        $this->post("/offers/$id/issue", $this->formFromDb($id));
        $this->post("/offers/$id/status", ['status' => 'accepted']);
        $pages['view'] = $this->get("/offers/$id");
        $pages['list'] = $this->get('/offers');
        $pages['search'] = $this->getQ('/offers', ['q' => $evil]);
        $pages['new'] = $this->get('/offers/new');
        $pages['err422'] = $this->post('/offers', $this->form($c, ['valid_until' => 'x'], [$this->line(['name' => $evil, 'price' => 'x'])]));
        foreach ($pages as $name => $r) {
            $this->assertStringNotContainsString('<script>alert', $r->body, $name);
            $this->assertStringNotContainsString('<i>1</i>', $r->body, $name);
            $this->assertStringNotContainsString('<b>X</b>', $r->body, $name);
        }
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $pages['view']->body);
        $this->assertStringContainsString('&lt;script&gt;', $pages['list']->body);
    }

    // ---- pages in all languages, nav ----

    public function test_pages_render_in_all_languages_with_nav_item(): void
    {
        $this->login();
        $id = $this->issued();
        $this->post("/offers/$id/status", ['status' => 'accepted']);
        foreach (['de' => ['Angebote', 'Gültig bis', 'Angenommen'], 'pl' => ['Oferty', 'Ważna do', 'Zaakceptowana'], 'en' => ['Quotes', 'Valid until', 'Accepted']] as $lang => $words) {
            $this->db->exec('UPDATE users SET ui_lang = ?', [$lang]);
            $this->app->session()->set('lang', $lang);
            foreach (['/offers', "/offers/$id", '/offers/new'] as $path) {
                $r = $this->get($path);
                $this->assertSame(200, $r->status, "$lang $path");
                $this->assertMatchesRegularExpression('#<a class="nav-item[^"]*"[^>]*data-nav="offers"#', $r->body, "$lang nav item");
            }
            $list = $this->get('/offers')->body;
            foreach ($words as $w) {
                $this->assertStringContainsString($w, $list, "$lang $w");
            }
            $draft = $this->createDraft();
            $this->assertStringContainsString($words[1], $this->get("/offers/$draft")->body);
            $this->assertStringContainsString($words[1], $this->get("/offers/$id")->body);
        }
    }

    // ---- gate, VAT and suspension ----

    public function test_company_without_tax_ids_can_issue_an_offer_but_not_an_invoice(): void
    {
        $bare = $this->company('Bare GmbH', ['address' => '', 'vat_id' => null, 'iban' => null, 'bic' => null]);
        $this->db->exec('DELETE FROM company_users');
        $this->addMember((int) $this->db->one('SELECT id FROM users')['id'], $bare);
        $customer = $this->customer($bare, ['name' => 'Ohne Anschrift', 'address' => '']);
        $this->login();
        $id = $this->createDraft($customer);
        $r = $this->post("/offers/$id/issue", $this->formFromDb($id));
        $this->assertSame(303, $r->status, substr($r->body, 0, 1500));
        $this->assertSame('AN-2026-001', $this->doc($id)['number']);
        $inv = $this->post('/invoices', ['customer_id' => (string) $customer, 'lang' => 'de', 'currency' => 'EUR', 'issue_date' => '2026-11-18', 'service_date' => '2026-11-18',
            'due_date' => '2026-12-02', 'lines' => $this->makietaLines(), 'intent' => 'issue']);
        $this->assertSame(422, $inv->status);
        $this->assertStringContainsString('data-missing="seller"', $inv->body);
        $this->assertStringContainsString('data-missing="buyer"', $inv->body);
    }

    public function test_unconfirmed_vies_shows_a_note_without_checkbox_and_never_blocks_the_offer(): void
    {
        $this->login();
        $id = $this->createDraft($this->plUnverified, ['lang' => 'pl']);
        $editor = $this->get("/offers/$id");
        $this->assertStringContainsString('banner banner-warn', $editor->body);
        $this->assertStringContainsString($this->app->t('offer.vies_unconfirmed'), $editor->body);
        $this->assertStringNotContainsString('confirm_vat', $editor->body);
        $r = $this->post("/offers/$id/issue", $this->formFromDb($id));
        $this->assertSame(303, $r->status, substr($r->body, 0, 2000));
        $d = $this->doc($id);
        $this->assertSame(['issued', 0, 'vies_not_confirmed'], [$d['status'], (int) $d['reverse_charge'], $d['vat_reason']]);
        // an invoice for the same customer still needs the confirmation
        $inv = $this->post('/invoices', ['customer_id' => (string) $this->plUnverified, 'lang' => 'pl', 'currency' => 'EUR', 'issue_date' => '2026-11-18', 'service_date' => '2026-11-18',
            'due_date' => '2026-12-02', 'lines' => $this->makietaLines(), 'intent' => 'issue']);
        $this->assertSame(422, $inv->status);
        $this->assertStringContainsString('name="confirm_vat"', $inv->body);
    }

    public function test_reverse_charge_offer_banner_and_totals(): void
    {
        $this->login();
        $r = $this->getQ('/offers/new', ['customer_id' => (string) $this->plValid]);
        $this->assertStringContainsString('banner banner-ok', $r->body);
        $id = $this->createDraft($this->plValid, ['lang' => 'pl', 'currency' => 'PLN']);
        $this->assertSame(1, (int) $this->doc($id)['reverse_charge']);
        $this->assertSame(102000, (int) $this->doc($id)['gross_minor']);
    }

    public function test_suspended_company_prepares_and_decides_but_cannot_issue(): void
    {
        $this->login();
        $accepted = $this->accepted();
        $draft = $this->createDraft();
        $this->db->exec("UPDATE companies SET access_status = 'suspended' WHERE id = ?", [$this->companyA]);
        $this->app->resetContext();
        $editor = $this->get("/offers/$draft");
        $this->assertSame(200, $editor->status);
        $this->assertStringContainsString('id="issue-blocked"', $editor->body);
        $this->assertStringContainsString('disabled', $editor->body);
        $r = $this->post("/offers/$draft/issue", $this->formFromDb($draft));
        $this->assertSame(403, $r->status);
        $this->assertSame('draft', $this->doc($draft)['status']);
        $this->assertNull($this->doc($draft)['number']);
        $this->assertSame(303, $this->post("/offers/$draft/save", $this->formFromDb($draft))->status, 'drafts can still be saved');
        $this->assertSame(303, $this->post("/offers/$accepted/status", ['status' => 'sent'])->status);
        $this->assertSame(303, $this->post("/offers/$accepted/convert")->status);
        $this->assertSame(200, $this->get("/offers/$accepted")->status);
        $this->assertSame(200, $this->get('/offers')->status);
    }

    // ---- editor mechanics ----

    public function test_save_delete_and_locked_redirects(): void
    {
        $this->login();
        $id = $this->createDraft();
        $form = $this->formFromDb($id);
        $form['notes'] = 'neu';
        $form['valid_until'] = '2026-12-31';
        $r = $this->post("/offers/$id/save", $form);
        $this->assertSame("/offers/$id", $r->headers['Location']);
        $this->assertSame(['neu', '2026-12-31'], [$this->doc($id)['notes'], $this->doc($id)['valid_until']]);
        $this->assertStringContainsString('Entwurf gespeichert.', $this->get("/offers/$id")->body);
        $r = $this->post("/offers/$id/delete");
        $this->assertSame('/offers', $r->headers['Location']);
        $this->assertSame(0, $this->docCount());
        $this->assertStringContainsString('Entwurf gelöscht.', $this->get('/offers')->body);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS c FROM document_lines')['c']);

        $issued = $this->issued();
        $before = $this->doc($issued);
        foreach (['save', 'issue', 'delete'] as $action) {
            $r = $this->post("/offers/$issued/$action", $this->formFromDb($issued) + ['notes' => 'hack']);
            $this->assertSame(303, $r->status, $action);
            $this->assertSame("/offers/$issued", $r->headers['Location']);
        }
        $this->assertSame($before, $this->doc($issued));
        $this->assertStringContainsString('kann nicht mehr geändert', $this->get("/offers/$issued")->body);
    }

    public function test_row_buttons_rerender_without_saving(): void
    {
        $this->login();
        $r = $this->post('/offers', $this->form($this->de) + ['add_line' => '1']);
        $this->assertSame(200, $r->status);
        $this->assertSame(0, $this->docCount());
        $this->assertStringContainsString('name="valid_until"', $r->body);
        $this->assertStringContainsString('action="/offers"', $r->body);
        $this->assertSame(4, substr_count($r->body, 'class="line-row"'));
    }

    public function test_recalc_for_offers_returns_server_totals(): void
    {
        $this->login();
        $before = $this->docCount();
        $r = $this->recalc('/offers/recalc', $this->form($this->de));
        $this->assertSame(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        $this->assertSame([102000, 120660], [$j['net'], $j['gross']]);
        $this->assertSame('1.206,60 €', $this->plain($j['formatted']['gross']));
        $this->assertSame($before, $this->docCount());
        $this->assertSame(419, $this->app->handle(new Request('POST', '/offers/recalc', post: $this->form($this->de), server: ['REMOTE_ADDR' => self::IP]))->status);
    }

    // ---- PDF ----

    private function requirePoppler(): void
    {
        if (!PdfFixture::hasPoppler()) {
            $this->markTestSkipped('pdftotext/pdfinfo (poppler-utils) are needed to read the rendered PDF.');
        }
    }

    public function test_offer_pdf_content(): void
    {
        $this->requirePoppler();
        $this->login();
        $id = $this->issued();
        $r = $this->get("/documents/$id/pdf");
        $this->assertSame(200, $r->status, substr($r->body, 0, 500));
        $this->assertSame('application/pdf', $r->headers['Content-Type']);
        $this->assertSame('inline; filename="AN-2026-001.pdf"', $r->headers['Content-Disposition']);
        $t = PdfFixture::text($r->body);
        foreach (['ANGEBOT', 'AN-2026-001', 'Angebotsdatum', '18.11.2026', 'Gültig bis', '18.12.2026', 'Bauhaus Logistik GmbH', 'Beratung / Workshop',
            '1.020,00 €', '1.206,60 €', 'Hallo', 'Danke', 'Muster Service GmbH', 'USt-IdNr.'] as $needle) {
            $this->assertStringContainsString($needle, $t, $needle);
        }
        foreach (['IBAN', 'DE89370400440532013000', 'COBADEFFXXX', 'Zahlbar', 'Bankverbindung', 'Fällig', 'RECHNUNG', 'RECHNUNGSEMPFÄNGER'] as $absent) {
            $this->assertStringNotContainsString($absent, $t, $absent);
        }
        $this->assertSame('AN-2026-001.pdf', basename((string) preg_replace('/^.*filename="([^"]+)".*$/', '$1', $r->headers['Content-Disposition'])));
        $this->assertSame(409, $this->get('/documents/' . $this->createDraft() . '/pdf')->status);
    }

    public function test_offer_pdf_titles_and_reverse_charge_note(): void
    {
        $this->requirePoppler();
        $this->login();
        $pl = $this->issued($this->plValid, ['lang' => 'pl', 'currency' => 'PLN']);
        $t = PdfFixture::text($this->get("/documents/$pl/pdf")->body);
        foreach (['OFERTA', 'Data oferty', 'Ważna do', 'OFERTA DLA'] as $needle) {
            $this->assertStringContainsString($needle, $t, $needle);
        }
        $this->assertStringContainsString($this->app->translator()->t('invoice.reverse_charge_note', 'pl'), preg_replace('/\s+/', ' ', $t) ?? '');
        $this->assertStringNotContainsString('IBAN', $t);
        $en = $this->issued($this->de, ['lang' => 'en']);
        $t = PdfFixture::text($this->get("/documents/$en/pdf")->body);
        foreach (['QUOTE', 'Quote date', 'Valid until'] as $needle) {
            $this->assertStringContainsString($needle, $t, $needle);
        }
    }

    public function test_foreign_currency_offer_pdf_shows_vat_in_eur(): void
    {
        $this->requirePoppler();
        $this->login();
        $id = $this->issued($this->de, ['currency' => 'PLN', 'eur_rate' => '4,2650']);
        $t = PdfFixture::text($this->get("/documents/$id/pdf")->body);
        $this->assertStringContainsString('USt in EUR', $t);
        $this->assertStringContainsString('1 EUR = 4,2650 PLN', $t);
        $this->assertStringNotContainsString('IBAN', $t);
    }

    // ---- mail ----

    public function test_mail_preview_and_send_marks_the_offer_sent(): void
    {
        $this->requirePoppler();
        $this->smtp($this->companyA);
        $this->login();
        $id = $this->issued();
        $form = $this->get("/documents/$id/send");
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('Angebot AN-2026-001 von Muster Service GmbH', $form->body);
        $this->assertStringContainsString('anbei erhalten Sie unser Angebot AN-2026-001', $this->plain(html_entity_decode($form->body)));
        $this->assertStringContainsString('gültig bis zum 18.12.2026', html_entity_decode($form->body));
        $this->assertStringContainsString('href="/offers/' . $id . '"', $form->body, 'back to the offer, not the invoice page');
        foreach (['IBAN', 'Bankverbindung', 'überweisen'] as $absent) {
            $this->assertStringNotContainsString($absent, html_entity_decode($form->body), $absent);
        }
        $this->assertSame('open', $this->doc($id)['offer_status'], 'the preview sends nothing and changes nothing');
        $this->assertSame([], $this->transport->sent);

        $r = $this->post("/documents/$id/send", ['to' => 'buchhaltung@bauhaus.example', 'subject' => 'Angebot AN-2026-001 von Muster Service GmbH', 'body' => "Guten Tag,\n\nanbei das Angebot."]);
        $this->assertSame(303, $r->status, substr($r->body, 0, 1500));
        $this->assertSame("/offers/$id", $r->headers['Location']);
        $this->assertCount(1, $this->transport->sent);
        $this->assertStringStartsWith('%PDF-', $this->transport->sent[0]['attachments'][0]['content']);
        $this->assertSame('AN-2026-001.pdf', $this->transport->sent[0]['attachments'][0]['name']);
        $this->assertSame('sent', $this->doc($id)['offer_status']);
        $this->assertCount(1, $this->db->all("SELECT 1 FROM audit_log WHERE action = 'document.offer_status'"));
        $view = $this->get("/offers/$id")->body;
        $this->assertStringContainsString('E-Mail an buchhaltung@bauhaus.example gesendet.', $view);
        $this->assertStringContainsString('Erneut per E-Mail senden', $view);
        $this->assertStringContainsString('Gesendet', $view);
    }

    public function test_failed_send_keeps_the_offer_open_and_other_statuses_stay(): void
    {
        $this->smtp($this->companyA);
        $this->login();
        $id = $this->issued();
        $this->transport->failWith = 'connect';
        $r = $this->post("/documents/$id/send", ['to' => 'buchhaltung@bauhaus.example', 'subject' => 'Betreff', 'body' => 'Text']);
        $this->assertSame(502, $r->status);
        $this->assertSame('open', $this->doc($id)['offer_status']);
        $this->assertSame([], $this->db->all("SELECT 1 FROM audit_log WHERE action = 'document.offer_status'"));
        $this->transport->failWith = null;
        $this->post("/offers/$id/status", ['status' => 'accepted']);
        $this->post("/documents/$id/send", ['to' => 'buchhaltung@bauhaus.example', 'subject' => 'Betreff', 'body' => 'Text']);
        $this->assertSame('accepted', $this->doc($id)['offer_status'], 'a decided offer is not moved back to sent');
        $this->assertSame('/offers/' . $id, $this->post("/documents/$id/send", ['to' => 'buchhaltung@bauhaus.example', 'subject' => 'Betreff', 'body' => 'Text'])->headers['Location']);
    }

    public function test_offer_mail_texts_in_pl_and_en(): void
    {
        $this->smtp($this->companyA);
        $this->login();
        $pl = $this->issued($this->plValid, ['lang' => 'pl', 'currency' => 'PLN']);
        $f = html_entity_decode($this->get("/documents/$pl/send")->body);
        $this->assertStringContainsString('Oferta AN-2026-001 od Muster Service GmbH', $f);
        $this->assertStringContainsString('w załączniku przesyłamy ofertę AN-2026-001', $f);
        $this->assertStringContainsString('Oferta jest ważna do dnia 18.12.2026.', $f);
        $en = $this->issued($this->de, ['lang' => 'en']);
        $f = html_entity_decode($this->get("/documents/$en/send")->body);
        $this->assertStringContainsString('Quote AN-2026-002 from Muster Service GmbH', $f);
        $this->assertStringContainsString('please find attached our quote AN-2026-002', $f);
        $this->assertStringContainsString('The quote is valid until 18/12/2026.', $f);
    }
}
