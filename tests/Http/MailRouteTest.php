<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Domain\DocumentService;
use App\Domain\NumberSequences;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Tenant\CompanyContext;
use Tests\Pdf\PdfFixture;
use Tests\Support\FakeResolver;
use Tests\Support\FakeTransport;

/** GET/POST /documents/{id}/send, the log and buttons on the issued view, POST /settings/mail/test. */
final class MailRouteTest extends HttpTestCase
{
    private const PASSWORD_SMTP = 'S3cr3t-Passw0rd!';

    private FakeTransport $transport;
    /** Shared with PdfRouteTest (font cache built once), see setUpBeforeClass(). */
    private static string $storage = '';
    private int $companyA;
    private int $companyB;
    private int $userId;
    private string $secret;
    private int $customer;
    private int $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeTransport();
        $this->app = $this->makeApp(['storage_path' => self::$storage, 'mail_transport' => $this->transport, 'mail_host_resolver' => FakeResolver::publicHosts()]);
        $this->now = (new \DateTimeImmutable('2026-11-18 10:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp();
        $this->companyA = $this->company('Muster Service GmbH');
        $this->companyB = $this->company('Fremd AG');
        $user = $this->createUser();
        $this->userId = $user['id'];
        $this->secret = $user['secret'];
        $this->addMember($this->userId, $this->companyA);
        $this->customer = $this->customer($this->companyA);
        $this->doc = $this->issued($this->companyA, $this->customer);
        $this->smtp($this->companyA);
    }

    public static function setUpBeforeClass(): void
    {
        // Same stable directory as PdfRouteTest: Dompdf keeps static font state keyed by the cache path,
        // so a per-run directory that is deleted afterwards breaks the PDF tests that follow in the same process.
        self::$storage = sys_get_temp_dir() . '/faktura-pdf-route-storage';
        if (!is_dir(self::$storage . '/logos')) {
            mkdir(self::$storage . '/logos', 0775, true);
        }
    }

    /**
     * Every route of the mail feature, taken from config/routes.php (so a new route cannot be missed).
     *
     * @return list<array{0: string, 1: string}> method and a concrete path
     */
    private function mailRoutes(): array
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
            if (preg_match('#/send$|^/settings/mail/test$#', $pattern) === 1) {
                $out[] = [$method, (string) preg_replace('/\{\w+(?::[^}]*)?\}/', (string) $this->doc, $pattern)];
            }
        }
        $this->assertGreaterThanOrEqual(3, count($out), 'mail routes not found in config/routes.php');
        return $out;
    }

    private function company(string $name): int
    {
        $this->db->exec('INSERT INTO companies (name, address, vat_id, iban, bic) VALUES (?, ?, ?, ?, ?)',
            [$name, "Musterweg 1\n10115 Berlin", 'DE987654321', 'DE89370400440532013000', 'COBADEFFXXX']);
        return $this->db->lastId();
    }

    private function smtp(int $company): void
    {
        $this->db->exec('UPDATE companies SET smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass_enc = ?, smtp_from_name = ?, smtp_from_addr = ? WHERE id = ?',
            ['smtp.firma.example', 587, 'rechnung@firma.example', $this->app->crypto()->encrypt(self::PASSWORD_SMTP), 'Muster Service', 'rechnung@firma.example', $company]);
    }

    private function service(int $company): DocumentService
    {
        $member = $this->db->one('SELECT user_id FROM company_users WHERE company_id = ? LIMIT 1', [$company]);
        if ($member === null) {
            $this->db->exec("INSERT INTO users (email, password_hash) VALUES (?, 'x')", ['b' . $company . '@example.com']);
            $uid = $this->db->lastId();
            $this->addMember($uid, $company);
            $member = ['user_id' => $uid];
        }
        $ctx = CompanyContext::forUser($this->db, (int) $member['user_id'], $company);
        $clock = fn (): int => $this->now;
        return new DocumentService($this->db, $ctx, new NumberSequences($this->db, $ctx, $clock), new DocumentRepo($this->db, $ctx), new CustomerRepo($this->db, $ctx), $clock);
    }

    private function customer(int $company, array $data = []): int
    {
        $this->service($company);
        $member = (int) $this->db->one('SELECT user_id FROM company_users WHERE company_id = ? LIMIT 1', [$company])['user_id'];
        return (new CustomerRepo($this->db, CompanyContext::forUser($this->db, $member, $company)))->save($data + [
            'name' => 'Bauhaus Logistik GmbH', 'address' => "Hauptstraße 5\n80331 München", 'country' => 'DE', 'vat_id' => 'DE123456789',
            'is_company' => '1', 'payment_days' => '14', 'default_lang' => 'de', 'default_currency' => 'EUR', 'email' => 'buchhaltung@bauhaus.example']);
    }

    private function draft(int $company, int $customer, string $type = 'invoice'): int
    {
        return $this->service($company)->createDraft($type, ['customer_id' => $customer, 'issue_date' => '2026-11-18', 'lines' => PdfFixture::makietaLines()]);
    }

    private function issued(int $company, int $customer, string $type = 'invoice'): int
    {
        $id = $this->draft($company, $customer, $type);
        $this->service($company)->issue($id, new \DateTimeImmutable('2026-11-18 10:00', new \DateTimeZone('Europe/Berlin')), true);
        return $id;
    }

    private function login(): void
    {
        $this->loginFully($this->secret);
    }

    /** @return array<string, string> */
    private function form(array $over = []): array
    {
        return $over + ['to' => 'kunde@example.de', 'subject' => 'Rechnung RE-2026-001', 'body' => "Hallo,\nanbei die Rechnung.\n"];
    }

    private function dump(): string
    {
        $out = '';
        foreach (['email_log', 'audit_log', 'login_attempts'] as $t) {
            $out .= json_encode($this->db->all("SELECT * FROM $t"));
        }
        return $out;
    }

    private function rows(string $t): int
    {
        return (int) $this->db->one("SELECT COUNT(*) AS n FROM $t")['n'];
    }

    // ---- access ----

    public function test_unauthenticated_goes_to_login_and_sends_nothing(): void
    {
        foreach ($this->mailRoutes() as [$method, $path]) {
            $this->assertRedirect('/login', $method === 'GET' ? $this->get($path) : $this->post($path, $this->form()));
        }
        $this->assertSame([], $this->transport->sent);
    }

    public function test_user_without_company_is_403(): void
    {
        $other = $this->createUser('solo@example.pl');
        $this->loginFully($other['secret'], 'solo@example.pl');
        $this->assertSame(403, $this->get("/documents/{$this->doc}/send")->status);
        $this->assertSame(403, $this->post("/documents/{$this->doc}/send", $this->form())->status);
        $this->assertSame([], $this->transport->sent);
    }

    public function test_post_without_csrf_is_419(): void
    {
        $this->login();
        $this->assertSame(419, $this->post("/documents/{$this->doc}/send", $this->form(), csrf: false)->status);
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, $this->rows('email_log'));
    }

    // ---- GET ----

    public function test_get_shows_the_editable_preview_without_side_effects(): void
    {
        $this->login();
        $before = [$this->rows('email_log'), $this->rows('audit_log'), $this->rows('login_attempts')];
        $r = $this->get("/documents/{$this->doc}/send");
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('name="to" value="buchhaltung@bauhaus.example"', $r->body);
        $this->assertStringContainsString('value="Rechnung RE-2026-001 von Muster Service GmbH"', $r->body);
        $this->assertStringContainsString('<textarea', $r->body);
        $this->assertStringContainsString('Guten Tag Bauhaus Logistik GmbH,', $r->body);
        $this->assertStringContainsString('RE-2026-001.pdf', $r->body);
        $this->assertStringContainsString("/documents/{$this->doc}/pdf", $r->body);
        $this->assertStringContainsString('name="_csrf"', $r->body);
        $this->assertStringNotContainsString('settings/mail', $r->body);
        $this->assertSame([], $this->transport->sent);
        $this->assertSame($before, [$this->rows('email_log'), $this->rows('audit_log'), $this->rows('login_attempts')]);
    }

    public function test_not_configured_owner_sees_settings_link_user_sees_explanation(): void
    {
        $this->db->exec('UPDATE companies SET smtp_host = NULL WHERE id = ?', [$this->companyA]);
        $this->login();
        $page = $this->get("/documents/{$this->doc}/send")->body;
        $this->assertStringContainsString('href="/settings/mail"', $page);
        $this->assertStringContainsString('noch nicht eingerichtet', $page);
        $this->assertStringContainsString('disabled', $page);
        $this->db->exec("UPDATE company_users SET role = 'user'");
        $this->app->resetContext();
        $page = $this->get("/documents/{$this->doc}/send")->body;
        $this->assertStringNotContainsString('href="/settings/mail"', $page);
        $this->assertStringContainsString('Inhaber', $page);
    }

    public function test_draft_is_409_foreign_unknown_and_unsupported_types_are_identical_404(): void
    {
        $draft = $this->draft($this->companyA, $this->customer);
        $foreign = $this->issued($this->companyB, $this->customer($this->companyB));
        // a type without mail support (synthetic value stored directly; offers and dunning letters are supported)
        $this->db->exec("INSERT INTO documents (company_id, type, status, number, lang, currency) VALUES (?, 'bogus_type', 'issued', 'X-1', 'de', 'EUR')", [$this->companyA]);
        $offer = $this->db->lastId();
        $this->login();
        $this->assertSame(409, $this->get("/documents/$draft/send")->status);
        $this->assertSame(409, $this->post("/documents/$draft/send", $this->form())->status);
        $unknown = $this->get('/documents/999999/send');
        $this->assertSame(404, $unknown->status);
        foreach ([$foreign, $offer] as $id) {
            $g = $this->get("/documents/$id/send");
            $this->assertSame(404, $g->status);
            $strip = fn (string $b): string => (string) preg_replace('/name="back" value="[^"]*"|name="_csrf" value="[^"]*"/', '', $b);
            $this->assertSame($strip($unknown->body), $strip($g->body));
            $this->assertSame(404, $this->post("/documents/$id/send", $this->form())->status);
        }
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, $this->rows('email_log'));
    }

    // ---- POST ----

    public function test_post_sends_redirects_with_flash_and_logs(): void
    {
        $this->login();
        $r = $this->post("/documents/{$this->doc}/send", $this->form());
        $this->assertSame(303, $r->status);
        $this->assertSame("/invoices/{$this->doc}", $r->headers['Location']);
        $this->assertCount(1, $this->transport->sent);
        $this->assertStringStartsWith('%PDF-', $this->transport->sent[0]['attachments'][0]['content']);
        $view = $this->get("/invoices/{$this->doc}");
        $this->assertStringContainsString('E-Mail an kunde@example.de gesendet.', $view->body);
        $this->assertStringContainsString('Gesendet', $view->body);
        $this->assertStringContainsString('Erneut per E-Mail senden', $view->body);
        $this->assertStringContainsString("/documents/{$this->doc}/send", $view->body);
        $this->assertStringNotContainsString('E-Mail an kunde@example.de gesendet.', $this->get("/invoices/{$this->doc}")->body, 'flash is shown once');
    }

    public function test_view_without_sends_offers_send_button_and_empty_log(): void
    {
        $this->login();
        $page = $this->get("/invoices/{$this->doc}")->body;
        $this->assertStringContainsString('Per E-Mail senden', $page);
        $this->assertStringNotContainsString('Erneut per E-Mail', $page);
        $this->assertStringContainsString('noch nicht per E-Mail gesendet', $page);
    }

    public function test_role_user_may_send(): void
    {
        $this->db->exec("UPDATE company_users SET role = 'user'");
        $this->login();
        $this->assertSame(303, $this->post("/documents/{$this->doc}/send", $this->form())->status);
        $this->assertCount(1, $this->transport->sent);
    }

    public function test_suspended_company_can_send_issued_documents(): void
    {
        $this->db->exec("UPDATE companies SET access_status = 'suspended' WHERE id = ?", [$this->companyA]);
        $this->login();
        $this->assertSame(303, $this->post("/documents/{$this->doc}/send", $this->form())->status);
    }

    public function test_unicode_survives_the_form_round_trip(): void
    {
        $this->login();
        $subject = 'Faktura – zażółć gęślą jaźń, Müller ß';
        $body = "Zażółć gęślą jaźń\r\nGrüße: äöüß\r\n";
        $this->post("/documents/{$this->doc}/send", $this->form(['subject' => $subject, 'body' => $body]));
        $this->assertSame($subject, $this->transport->sent[0]['subject']);
        $this->assertSame("Zażółć gęślą jaźń\nGrüße: äöüß\n", $this->transport->sent[0]['body']);
    }

    public function test_invalid_input_is_422_keeps_typed_values_escaped_and_sends_nothing(): void
    {
        $this->login();
        $r = $this->post("/documents/{$this->doc}/send", $this->form([
            'to' => '"><script>alert(1)</script>', 'subject' => "<b>x</b>\r\nBcc: evil@example.org", 'body' => '<img src=x onerror=alert(1)>',
        ]));
        $this->assertSame(422, $r->status);
        $this->assertStringNotContainsString('<script>alert(1)', $r->body);
        $this->assertStringNotContainsString('<img src=x', $r->body);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $r->body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $r->body);
        $this->assertStringContainsString('Gib genau eine gültige E-Mail-Adresse ein', $r->body);
        $this->assertStringContainsString('Der Betreff muss', $r->body);
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, $this->rows('email_log'));
    }

    public function test_header_injection_matrix_never_reaches_the_transport(): void
    {
        $this->login();
        foreach ([
            ['to' => "a@b.de\r\nBcc: x@y.de"], ['to' => "a@b.de\nBcc: x@y.de"], ['to' => 'a@b.de,Bcc:x@y.de'],
            ['subject' => "Hi\r\nBcc: x@y.de"], ['subject' => "Hi\nBcc: x@y.de"], ['subject' => "Hi\0"],
        ] as $over) {
            $this->assertSame(422, $this->post("/documents/{$this->doc}/send", $this->form($over))->status);
        }
        // "%0a" is just text, not a decoded line break: it stays inside the address and is passed on unchanged.
        $this->post("/documents/{$this->doc}/send", $this->form(['subject' => 'Hi%0aBcc: x@y.de']));
        $this->assertSame('Hi%0aBcc: x@y.de', $this->transport->sent[0]['subject']);
        $this->assertCount(1, $this->transport->sent);
    }

    public function test_transport_failure_shows_mapped_text_keeps_form_and_never_leaks_the_password(): void
    {
        $this->transport->failWith = 'auth';
        $this->login();
        $r = $this->post("/documents/{$this->doc}/send", $this->form(['subject' => 'Mein Betreff']));
        $this->assertSame(502, $r->status);
        $this->assertStringContainsString('Anmeldung am Mailserver fehlgeschlagen', $r->body);
        $this->assertStringContainsString('value="Mein Betreff"', $r->body);
        $this->assertStringNotContainsString(self::PASSWORD_SMTP, $r->body);
        $this->assertStringNotContainsString('smtp_pass', $r->body);
        $this->assertStringNotContainsString(self::PASSWORD_SMTP, $this->dump());
        $view = $this->get("/invoices/{$this->doc}")->body;
        $this->assertStringContainsString('Fehlgeschlagen', $view);
        $this->assertStringContainsString('Anmeldung am Mailserver fehlgeschlagen', $view);
        $this->assertStringNotContainsString(self::PASSWORD_SMTP, $view);
        $this->assertStringNotContainsString('Erneut per E-Mail', $view, 'a failed send is not "sent"');
    }

    public function test_not_configured_post_is_409_and_sends_nothing(): void
    {
        $this->db->exec('UPDATE companies SET smtp_pass_enc = NULL WHERE id = ?', [$this->companyA]);
        $this->login();
        $r = $this->post("/documents/{$this->doc}/send", $this->form());
        $this->assertSame(409, $r->status);
        $this->assertStringContainsString('nicht eingerichtet', $r->body);
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, $this->rows('email_log'));
    }

    public function test_rate_limit_is_429(): void
    {
        $this->login();
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(303, $this->post("/documents/{$this->doc}/send", $this->form())->status);
        }
        $r = $this->post("/documents/{$this->doc}/send", $this->form());
        $this->assertSame(429, $r->status);
        $this->assertStringContainsString('zu viele E-Mails', $r->body);
        $this->assertCount(20, $this->transport->sent);
    }

    public function test_other_companys_log_rows_are_never_shown(): void
    {
        $foreign = $this->issued($this->companyB, $this->customer($this->companyB));
        $this->db->exec("INSERT INTO email_log (company_id, document_id, to_email, subject, status, created_at) VALUES (?, ?, 'geheim-b@example.org', 'S', 'sent', '2026-11-18 10:00:00')", [$this->companyB, $foreign]);
        $this->db->exec("INSERT INTO email_log (company_id, document_id, to_email, subject, status, created_at) VALUES (?, ?, 'geheim-b2@example.org', 'S', 'sent', '2026-11-18 10:00:00')", [$this->companyB, $this->doc]);
        $this->login();
        $this->assertStringNotContainsString('geheim-b', $this->get("/invoices/{$this->doc}")->body);
        $this->assertStringNotContainsString('geheim-b', $this->get("/documents/{$this->doc}/send")->body);
        $this->assertSame(404, $this->get("/invoices/$foreign")->status);
    }

    // ---- Issue and prepare email ----

    private function issueForm(int $customer): array
    {
        $lines = [];
        foreach ([['Beratung', '2', '450,00', 'hour', '1900'], ['Anfahrt', '1', '60,00', 'flat', '1900']] as [$n, $q, $p, $u, $v]) {
            $lines[] = ['name' => $n, 'description' => '', 'qty' => $q, 'unit' => $u, 'price' => $p, 'discount' => '', 'vat_bp' => $v, 'item_id' => ''];
        }
        return ['customer_id' => (string) $customer, 'lang' => 'de', 'currency' => 'EUR', 'eur_rate' => '', 'issue_date' => '2026-11-18',
            'service_date' => '2026-11-18', 'due_date' => '2026-12-02', 'intro_text' => 'Hallo', 'outro_text' => 'Danke', 'notes' => '', 'lines' => $lines];
    }

    public function test_issue_and_prepare_email_redirects_to_the_send_page_without_sending(): void
    {
        $draft = $this->draft($this->companyA, $this->customer);
        $this->login();
        $editor = $this->get("/invoices/$draft")->body;
        $this->assertStringContainsString('name="then" value="send"', $editor);
        $this->assertStringContainsString('Ausstellen und E-Mail vorbereiten', $editor);
        $r = $this->post("/invoices/$draft/issue", $this->issueForm($this->customer) + ['then' => 'send']);
        $this->assertSame(303, $r->status);
        $this->assertSame("/documents/$draft/send", $r->headers['Location']);
        $this->assertSame('issued', $this->db->one('SELECT status FROM documents WHERE id = ?', [$draft])['status']);
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, $this->rows('email_log'));
        $page = $this->get("/documents/$draft/send");
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('RE-2026-002', $page->body);
    }

    public function test_plain_issue_still_goes_to_the_view_and_create_mode_has_the_button(): void
    {
        $draft = $this->draft($this->companyA, $this->customer);
        $this->login();
        $this->assertSame("/invoices/$draft", $this->post("/invoices/$draft/issue", $this->issueForm($this->customer))->headers['Location']);
        $new = $this->app->handle(new \App\Core\Request('GET', '/invoices/new', query: ['customer_id' => (string) $this->customer], server: ['REMOTE_ADDR' => self::IP]))->body;
        $this->assertStringContainsString('name="then" value="send"', $new);
        $r = $this->post('/invoices', $this->issueForm($this->customer) + ['then' => 'send']);
        $this->assertSame(303, $r->status);
        $this->assertMatchesRegularExpression('#^/documents/\d+/send$#', $r->headers['Location']);
        $this->assertSame([], $this->transport->sent);
    }

    // ---- settings test mail ----

    public function test_settings_test_mail_goes_to_the_login_address_of_the_current_user(): void
    {
        $this->login();
        $r = $this->post('/settings/mail/test', ['to' => 'evil@example.org']);
        $this->assertRedirect('/settings/mail', $r);
        $this->assertSame(303, $r->status);
        $page = $this->get('/settings/mail');
        $this->assertStringContainsString('Testmail an jan@example.pl gesendet.', $page->body);
        $this->assertStringNotContainsString('Testmail an jan@example.pl gesendet.', $this->get('/settings/mail')->body, 'result is shown once; a reload sends nothing');
        $this->assertCount(1, $this->transport->sent);
        $this->assertSame('jan@example.pl', $this->transport->sent[0]['to']);
        $this->assertSame([], $this->transport->sent[0]['attachments']);
        $this->assertSame(1, $this->rows('audit_log') - (int) $this->db->one("SELECT COUNT(*) AS n FROM audit_log WHERE action <> 'settings.mail_test'")['n']);
        $detail = (string) $this->db->one("SELECT detail FROM audit_log WHERE action = 'settings.mail_test'")['detail'];
        $this->assertStringNotContainsString('jan@example.pl', $detail);
    }

    public function test_settings_test_mail_is_owner_only_and_csrf_protected(): void
    {
        $this->login();
        $this->assertSame(419, $this->post('/settings/mail/test', [], csrf: false)->status);
        $this->db->exec("UPDATE company_users SET role = 'user'");
        $this->app->resetContext();
        $this->assertSame(403, $this->post('/settings/mail/test')->status);
        $this->assertSame([], $this->transport->sent);
    }

    public function test_settings_test_mail_unauthenticated_goes_to_login(): void
    {
        $this->assertRedirect('/login', $this->post('/settings/mail/test'));
    }

    public function test_settings_test_mail_failure_is_mapped_and_secret_free(): void
    {
        $this->transport->failWith = 'connect';
        $this->login();
        $this->assertRedirect('/settings/mail', $this->post('/settings/mail/test'));
        $body = $this->get('/settings/mail')->body;
        $this->assertStringContainsString('Der Mailserver ist nicht erreichbar', $body);
        $this->assertStringNotContainsString(self::PASSWORD_SMTP, $body);
        $this->assertStringNotContainsString(self::PASSWORD_SMTP, $this->dump());
    }

    public function test_settings_test_mail_needs_configuration_and_is_rate_limited(): void
    {
        $this->login();
        for ($i = 0; $i < 3; $i++) {
            $this->assertRedirect('/settings/mail', $this->post('/settings/mail/test'));
        }
        $this->assertRedirect('/settings/mail', $this->post('/settings/mail/test'));
        $this->assertStringContainsString('zu viele E-Mails', $this->get('/settings/mail')->body);
        $this->assertCount(3, $this->transport->sent);
        $this->db->exec('UPDATE companies SET smtp_host = NULL WHERE id = ?', [$this->companyA]);
        $this->assertRedirect('/settings/mail', $this->post('/settings/mail/test'));
        $this->assertStringContainsString('Speichere zuerst vollständige Einstellungen', $this->get('/settings/mail')->body);
        $this->assertCount(3, $this->transport->sent);
    }

    public function test_ssrf_target_fails_with_secret_free_text_for_test_mail_and_document_send(): void
    {
        $this->db->exec("UPDATE companies SET smtp_host = '127.0.0.1', smtp_port = 6379 WHERE id = ?", [$this->companyA]);
        $this->login();
        $this->assertRedirect('/settings/mail', $this->post('/settings/mail/test'));
        $mail = $this->get('/settings/mail')->body;
        $this->assertStringContainsString('Der Mailserver ist nicht erreichbar', $mail);
        $this->assertStringNotContainsString('127.0.0.1:6379', $mail);
        $r = $this->post("/documents/{$this->doc}/send", $this->form());
        $this->assertSame(502, $r->status);
        $this->assertStringContainsString('Der Mailserver ist nicht erreichbar', $r->body);
        $this->assertStringNotContainsString(self::PASSWORD_SMTP, $r->body . $mail . $this->dump());
        $this->assertSame([], $this->transport->sent);
    }
}
