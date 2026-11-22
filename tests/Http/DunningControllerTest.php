<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\Request;
use App\Core\Response;
use App\Domain\DocumentService;
use App\Domain\DunningLevel;
use App\Domain\NumberSequences;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Tenant\CompanyContext;
use Tests\Pdf\PdfFixture;
use Tests\Support\FakeResolver;
use Tests\Support\FakeTransport;

/**
 * Dunning UI (Task 21): overdue list with suggestions, the on-the-fly preview, creation (= issue),
 * the issued view, PDF and the explicit manual sending, plus isolation, CSRF, roles and escaping.
 * The rules themselves are tested in Tests\Domain\DunningServiceTest.
 */
final class DunningControllerTest extends HttpTestCase
{
    private const BERLIN = 'Europe/Berlin';

    private static string $storage = '';
    private FakeTransport $transport;
    private int $companyA;
    private int $companyB;
    private int $userId;
    private int $userB;
    private string $secret;
    private int $nordlicht;
    private int $becker;
    private int $kowalski;

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
        $this->userId = $user['id'];
        $this->addMember($this->userId, $this->companyA);
        $this->userB = $this->createUser('b@example.pl', false)['id'];
        $this->addMember($this->userB, $this->companyB);
        $this->nordlicht = $this->customer($this->companyA, ['name' => 'Nordlicht Werbung UG', 'address' => "Hafenstraße 3\n20457 Hamburg", 'email' => 'buchhaltung@nordlicht.example']);
        $this->becker = $this->customer($this->companyA, ['name' => 'Frau K. Becker', 'is_company' => 0, 'email' => 'k.becker@example.de']);
        $this->kowalski = $this->customer($this->companyA, ['name' => 'Kowalski Transport Sp. z o.o.', 'country' => 'PL', 'address' => "ul. Długa 12\n00-238 Warszawa",
            'default_lang' => 'pl', 'default_currency' => 'PLN', 'email' => 'biuro@kowalski.example']);
    }

    // ---- helpers ----

    private function company(string $name, array $over = []): int
    {
        $row = $over + ['name' => $name, 'address' => "Musterweg 1\n10115 Berlin", 'vat_id' => 'DE987654321', 'tax_number' => '27/123/45678',
            'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'bank' => 'Commerzbank', 'access_status' => 'active', 'dunning_base_rate_bp' => 127];
        $this->db->exec('INSERT INTO companies (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
        return $this->db->lastId();
    }

    private function smtp(int $company): void
    {
        $this->db->exec('UPDATE companies SET smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass_enc = ?, smtp_from_name = ?, smtp_from_addr = ? WHERE id = ?',
            ['smtp.firma.example', 587, 'buchhaltung@firma.example', $this->app->crypto()->encrypt('S3cr3t-Passw0rd!'), 'Muster Service', 'buchhaltung@firma.example', $company]);
    }

    private function customer(int $company, array $data = []): int
    {
        $row = ['company_id' => $company] + $data + ['name' => 'Kunde GmbH', 'address' => "Kundenweg 1\n10115 Berlin", 'country' => 'DE', 'is_company' => 1,
            'default_lang' => 'de', 'default_currency' => 'EUR', 'payment_days' => 14];
        $this->db->exec('INSERT INTO customers (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
        return $this->db->lastId();
    }

    private function svc(int $company = 0): DocumentService
    {
        $company = $company ?: $this->companyA;
        $ctx = CompanyContext::forUser($this->db, $company === $this->companyA ? $this->userId : $this->userB, $company);
        $clock = fn (): int => $this->now;
        return new DocumentService($this->db, $ctx, new NumberSequences($this->db, $ctx, $clock), new DocumentRepo($this->db, $ctx), new CustomerRepo($this->db, $ctx), $clock);
    }

    /** Issued invoice: net 1.200,00 + 19 % = 1.428,00, issued 14.10.2026, due 28.10.2026 (21 days overdue on 18.11.2026). */
    private function invoice(int $customer = 0, array $extra = [], int $company = 0, int $net = 120000): int
    {
        $svc = $this->svc($company);
        $id = $svc->createDraft('invoice', $extra + [
            'customer_id' => $customer ?: $this->nordlicht, 'issue_date' => '2026-10-14', 'due_date' => '2026-10-28',
            'lines' => [['name' => 'Kampagne Herbst', 'unit' => 'flat', 'qty_milli' => 1000, 'unit_minor' => $net, 'discount_bp' => 0, 'vat_bp' => 1900]],
        ]);
        $svc->issue($id, new \DateTimeImmutable('@' . $this->now));
        return $id;
    }

    private function pay(int $invoice, int $amount): void
    {
        $this->svc()->addPayment($invoice, $amount, new \DateTimeImmutable('2026-11-10 12:00', new \DateTimeZone(self::BERLIN)));
    }

    private function login(string $lang = 'de'): void
    {
        $this->loginFully($this->secret);
        if ($lang !== 'de') {
            $this->db->exec('UPDATE users SET ui_lang = ?', [$lang]);
            $this->app->session()->set('lang', $lang);
        }
    }

    private function getQ(string $path, array $query): Response
    {
        return $this->app->handle(new Request('GET', $path, query: $query, server: ['REMOTE_ADDR' => self::IP]));
    }

    /** @return array<string, mixed> */
    private function doc(int $id): array
    {
        return $this->db->one('SELECT * FROM documents WHERE id = ?', [$id]);
    }

    private function dunnings(): int
    {
        return (int) $this->db->one("SELECT COUNT(*) AS c FROM documents WHERE type = 'dunning'")['c'];
    }

    private function counter(int $company = 0): int
    {
        $ctx = CompanyContext::forUser($this->db, ($company ?: $this->companyA) === $this->companyA ? $this->userId : $this->userB, $company ?: $this->companyA);
        return (new NumberSequences($this->db, $ctx))->lastCounter('dunning', new \DateTimeImmutable('@' . $this->now));
    }

    private function plain(string $html): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
    }

    private function create(int $invoice, array $form = ['level' => '1']): Response
    {
        return $this->post('/dunning', ['invoice' => (string) $invoice] + $form);
    }

    private function createdId(Response $r): int
    {
        $this->assertSame(303, $r->status, substr($r->body, 0, 3000));
        $this->assertMatchesRegularExpression('#^/dunning/\d+$#', $r->headers['Location']);
        return (int) substr($r->headers['Location'], strlen('/dunning/'));
    }

    private function requirePoppler(): void
    {
        if (!PdfFixture::hasPoppler()) {
            $this->markTestSkipped('pdftotext/pdfinfo (poppler-utils) are needed to read the rendered PDF.');
        }
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

    // ---- brief tests ----

    public function test_paid_invoice_not_listed(): void
    {
        $open = $this->invoice();
        $paid = $this->invoice();
        $this->pay($paid, 142800);
        $credited = $this->invoice();
        $this->svc()->issue($this->svc()->createCorrection($credited), new \DateTimeImmutable('@' . $this->now));
        $notDue = $this->invoice(0, ['issue_date' => '2026-11-04', 'due_date' => '2026-11-18']);
        $this->login();
        $page = $this->get('/dunning')->body;
        $this->assertStringContainsString('data-overdue-row="' . $open . '"', $page);
        foreach ([$paid, $credited, $notDue] as $absent) {
            $this->assertStringNotContainsString('data-overdue-row="' . $absent . '"', $page);
        }
    }

    public function test_payment_after_suggestion_removes_invoice_from_list(): void
    {
        $id = $this->invoice();
        $this->login();
        $page = $this->get('/dunning')->body;
        $this->assertStringContainsString('data-overdue-row="' . $id . '"', $page);
        $this->assertStringContainsString('data-suggestion="1"', $page, '21 days overdue: 1st reminder suggested');
        $this->pay($id, 142800);
        $this->assertStringNotContainsString('data-overdue-row="' . $id . '"', $this->get('/dunning')->body);
        $this->assertStringContainsString('data-empty="overdue"', $this->get('/dunning')->body);
        // the preview of a now paid invoice redirects back with a flash
        $r = $this->getQ('/dunning/new', ['invoice' => (string) $id]);
        $this->assertRedirect('/dunning', $r);
        $this->assertStringContainsString('Die Rechnung ist bereits bezahlt.', $this->get('/dunning')->body);
    }

    public function test_interest_and_flat_fee_off_by_default(): void
    {
        $id = $this->invoice();
        $this->login();
        $page = $this->getQ('/dunning/new', ['invoice' => (string) $id]);
        $this->assertSame(200, $page->status);
        $this->assertDoesNotMatchRegularExpression('/name="(interest|flat_fee|fee)" value="1"[^>]*checked/', $page->body);
        $this->assertStringNotContainsString('data-row="interest"', $page->body);
        $this->assertStringNotContainsString('data-row="flat_fee"', $page->body);
        $this->assertStringContainsString('1.428,00 €', $this->plain($page->body));
        // the POST form carries no toggle unless one was chosen
        preg_match('#<form class="form-grid issue-form".*?</form>#s', $page->body, $m);
        $this->assertStringNotContainsString('name="interest"', $m[0]);
        $this->assertStringNotContainsString('name="flat_fee"', $m[0]);
        $d = $this->doc($this->createdId($this->create($id)));
        $this->assertSame([null, null, null, 142800], [$d['dunning_interest_minor'], $d['dunning_flat_fee_minor'], $d['dunning_fee_minor'], (int) $d['dunning_claim_minor']]);
    }

    public function test_flat_fee_unavailable_for_private_customer(): void
    {
        $id = $this->invoice($this->becker);
        $this->login();
        $page = $this->getQ('/dunning/new', ['invoice' => (string) $id, 'flat_fee' => '1'])->body;
        $this->assertMatchesRegularExpression('/name="flat_fee" value="1"[^>]*disabled/', $page);
        $this->assertStringContainsString('data-warning="consumer"', $page);
        $this->assertStringContainsString('Privatkunde: Die Verzugspauschale gibt es nur gegenüber Unternehmen.', $this->plain($page));
        $this->assertStringContainsString('6,27 % p. a. (Verbraucher)', $this->plain($page), 'B2C surcharge in the interest hint');
        $r = $this->create($id, ['level' => '1', 'flat_fee' => '1']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Die Verzugspauschale gibt es nur für Geschäftskunden', $this->plain($r->body));
        $this->assertSame([0, 0], [$this->dunnings(), $this->counter()]);
    }

    public function test_the_interest_hint_shows_the_companys_b2b_surcharge_not_a_fixed_number(): void
    {
        $this->db->exec('UPDATE companies SET dunning_base_rate_bp = NULL, dunning_b2b_surcharge_bp = 700 WHERE id = ?', [$this->companyA]);
        $id = $this->invoice();
        $this->login();
        $page = $this->plain($this->getQ('/dunning/new', ['invoice' => (string) $id])->body);
        $this->assertStringContainsString('Basiszins + 7 Prozentpunkte (B2B)', $page);
        $this->assertStringNotContainsString('+ 9 Prozentpunkte', $page);
    }

    public function test_interest_disabled_without_base_rate(): void
    {
        $this->db->exec('UPDATE companies SET dunning_base_rate_bp = NULL WHERE id = ?', [$this->companyA]);
        $id = $this->invoice();
        $this->login();
        $page = $this->getQ('/dunning/new', ['invoice' => (string) $id])->body;
        $this->assertMatchesRegularExpression('/name="interest" value="1"[^>]*disabled/', $page);
        $this->assertStringContainsString('data-warning="no_base_rate"', $page);
        $this->assertStringContainsString('href="/settings/dunning"', $page, 'owners get the link to the settings');
        $r = $this->create($id, ['level' => '1', 'interest' => '1']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Verzugszinsen gehen erst, wenn der Basiszinssatz', $this->plain($r->body));
        $this->assertSame([0, 0], [$this->dunnings(), $this->counter()]);
        // a crafted GET with interest=1 shows the same error and no breakdown
        $crafted = $this->getQ('/dunning/new', ['invoice' => (string) $id, 'interest' => '1']);
        $this->assertSame(422, $crafted->status);
        $this->assertStringNotContainsString('class="form-grid issue-form"', $crafted->body);
        // role user: no settings link, a hint to ask the owner
        $this->db->exec("UPDATE company_users SET role = 'user' WHERE user_id = ?", [$this->userId]);
        $this->app->resetContext();
        $page = $this->getQ('/dunning/new', ['invoice' => (string) $id])->body;
        $this->assertStringNotContainsString('href="/settings/dunning"', $page);
        $this->assertStringContainsString('Bitte die Inhaberin oder den Inhaber', $page);
    }

    public function test_nothing_sent_without_explicit_post(): void
    {
        $this->requirePoppler();
        $this->smtp($this->companyA);
        $id = $this->invoice();
        $this->login();
        $this->getQ('/dunning/new', ['invoice' => (string) $id, 'level' => '1', 'interest' => '1', 'flat_fee' => '1']);
        $dunning = $this->createdId($this->create($id, ['level' => '1', 'interest' => '1', 'flat_fee' => '1']));
        $this->get('/dunning/' . $dunning);
        $this->get('/dunning');
        $form = $this->get("/documents/$dunning/send");
        $this->assertSame(200, $form->status);
        $this->assertSame([], $this->transport->sent, 'creating, viewing and opening the send page send nothing');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS c FROM email_log')['c']);
        $text = $this->plain($form->body);
        $this->assertStringContainsString('1. Mahnung MA-2611-001 zu Rechnung RE-2026-001 – Muster Service GmbH', $text);
        $this->assertStringContainsString('Sehr geehrte Damen und Herren,', $text);
        $this->assertStringContainsString('zu unserer Rechnung RE-2026-001 vom 14.10.2026', $text);
        $this->assertStringContainsString('Gesamtforderung: 1.476,44 €', $text);
        $this->assertStringContainsString('bis zum 25.11.2026', $text);
        $this->assertStringContainsString('IBAN: DE89370400440532013000', $text);
        $this->assertStringContainsString('href="/dunning/' . $dunning . '"', $form->body, 'back to the dunning letter');
        $invoiceBefore = $this->doc($id);

        $r = $this->post("/documents/$dunning/send", ['to' => 'buchhaltung@nordlicht.example', 'subject' => '1. Mahnung MA-2611-001', 'body' => "Sehr geehrte Damen und Herren,\n\nanbei unsere Mahnung."]);
        $this->assertSame(303, $r->status, substr($r->body, 0, 1500));
        $this->assertSame("/dunning/$dunning", $r->headers['Location']);
        $this->assertCount(1, $this->transport->sent);
        $sent = $this->transport->sent[0];
        $this->assertSame('MA-2611-001.pdf', $sent['attachments'][0]['name']);
        $this->assertStringStartsWith('%PDF-', $sent['attachments'][0]['content']);
        $this->assertStringContainsString('1. MAHNUNG', PdfFixture::text($sent['attachments'][0]['content']));
        $this->assertStringContainsString('Sehr geehrte Damen und Herren', $sent['body']);
        $log = $this->db->all('SELECT * FROM email_log');
        $this->assertCount(1, $log);
        $this->assertSame([$dunning, 'sent'], [(int) $log[0]['document_id'], $log[0]['status']]);
        $view = $this->get("/dunning/$dunning")->body;
        $this->assertStringContainsString('E-Mail an buchhaltung@nordlicht.example gesendet.', $view);
        $this->assertStringContainsString('Erneut per E-Mail senden', $view);
        $this->assertSame($invoiceBefore, $this->doc($id), 'sending a dunning letter changes nothing on the invoice');
        $this->assertNull($this->doc($dunning)['offer_status']);
    }

    public function test_foreign_currency_invoice_dunning_keeps_currency(): void
    {
        $this->db->exec('UPDATE companies SET dunning_fee_minor = 500 WHERE id = ?', [$this->companyA]);
        $id = $this->invoice($this->kowalski, ['eur_rate' => '4.2650', 'lang' => 'pl']);
        $this->login();
        $page = $this->getQ('/dunning/new', ['invoice' => (string) $id, 'interest' => '1'])->body;
        $text = $this->plain($page);
        $this->assertStringContainsString('data-warning="non_eur"', $page);
        $this->assertStringContainsString('Forderung in PLN', $text);
        $this->assertMatchesRegularExpression('/name="flat_fee" value="1"[^>]*disabled/', $page);
        $this->assertStringNotContainsString('name="fee"', $page, 'the fee toggle is only shown for euro claims');
        $this->assertStringContainsString('1.436,44 PLN', $text, 'interest in PLN (8,44) on 1.428,00 PLN, breakdown in the UI language');
        $this->assertStringContainsString('1 436,44 zł', $text, 'the letter in Polish');
        $this->assertStringContainsString('1. wezwanie do zapłaty', $text, 'the letter is in the invoice language');
        $this->assertSame(422, $this->create($id, ['level' => '1', 'fee' => '1'])->status);
        $this->assertSame(422, $this->create($id, ['level' => '1', 'flat_fee' => '1'])->status);
        $this->assertSame(0, $this->dunnings());
        $d = $this->doc($this->createdId($this->create($id, ['level' => '1', 'interest' => '1'])));
        $this->assertSame(['PLN', 'pl', 844, 143644, null], [$d['currency'], $d['lang'], (int) $d['dunning_interest_minor'], (int) $d['dunning_claim_minor'], $d['vat_eur_minor']]);
    }

    public function test_dunning_gets_own_number_from_dunning_sequence(): void
    {
        $id = $this->invoice();
        $this->login();
        $r = $this->create($id, ['level' => '0']);
        $dunning = $this->createdId($r);
        $this->assertSame(['dunning', 'issued', 'MA-2611-001', $id], [$this->doc($dunning)['type'], $this->doc($dunning)['status'], $this->doc($dunning)['number'], (int) $this->doc($dunning)['related_document_id']]);
        $this->assertStringContainsString('Mahnung MA-2611-001 ausgestellt. Sie wurde nicht verschickt.', $this->get("/dunning/$dunning")->body);
        $second = $this->createdId($this->create($id, ['level' => '2']));
        $this->assertSame('MA-2611-002', $this->doc($second)['number']);
        $this->assertSame('RE-2026-002', $this->doc($this->invoice())['number'], 'the invoice sequence did not move');
    }

    // ---- preview ----

    public function test_preview_numbers_equal_what_post_stores(): void
    {
        $id = $this->invoice();
        $this->login();
        $page = $this->getQ('/dunning/new', ['invoice' => (string) $id, 'level' => '1', 'interest' => '1', 'flat_fee' => '1']);
        $this->assertSame(200, $page->status);
        $text = $this->plain($page->body);
        foreach (['1.428,00 €', '8,44 €', '40,00 €', '1.476,44 €', '25.11.2026', 'Verzugszinsen 10,27 % p. a. für 21 Tage', 'Verzugspauschale gemäß § 288 Abs. 5 BGB',
            'Rechnung RE-2026-001 vom 14.10.2026, fällig am 28.10.2026', 'Sehr geehrte Damen und Herren,', 'Text des Schreibens (Deutsch)'] as $needle) {
            $this->assertStringContainsString($needle, $text, $needle);
        }
        preg_match('#<form class="form-grid issue-form".*?</form>#s', $page->body, $m);
        $this->assertStringContainsString('name="level" value="1"', $m[0]);
        $this->assertStringContainsString('name="interest" value="1"', $m[0]);
        $this->assertStringContainsString('name="flat_fee" value="1"', $m[0]);
        $dunning = $this->createdId($this->create($id, ['level' => '1', 'interest' => '1', 'flat_fee' => '1']));
        $d = $this->doc($dunning);
        $this->assertSame([1, 142800, 844, 21, 1027, 4000, null, 147644, '2026-11-25'], [(int) $d['dunning_level'], (int) $d['dunning_outstanding_minor'],
            (int) $d['dunning_interest_minor'], (int) $d['dunning_interest_days'], (int) $d['dunning_interest_rate_bp'], (int) $d['dunning_flat_fee_minor'],
            $d['dunning_fee_minor'], (int) $d['dunning_claim_minor'], $d['dunning_pay_by']]);
        $view = $this->plain($this->get("/dunning/$dunning")->body);
        foreach (['MA-2611-001', '8,44 €', '40,00 €', '1.476,44 €', '25.11.2026', 'RE-2026-001'] as $needle) {
            $this->assertStringContainsString($needle, $view, "view: $needle");
        }
    }

    public function test_level_select_lists_only_higher_levels_with_the_suggestion_preselected(): void
    {
        $id = $this->invoice(0, ['issue_date' => '2026-09-01', 'due_date' => '2026-10-10']); // 39 days: 2nd reminder suggested
        $this->login();
        $page = $this->getQ('/dunning/new', ['invoice' => (string) $id])->body;
        $this->assertMatchesRegularExpression('#<option value="2" selected>2\. Mahnung · Vorschlag</option>#', $page);
        foreach (['0', '1', '3'] as $v) {
            $this->assertStringContainsString('<option value="' . $v . '"', $page);
        }
        $this->createdId($this->create($id, ['level' => '1']));
        $page = $this->getQ('/dunning/new', ['invoice' => (string) $id])->body;
        $this->assertStringNotContainsString('<option value="0"', $page);
        $this->assertStringNotContainsString('<option value="1"', $page);
        $this->assertMatchesRegularExpression('#<option value="2" selected>#', $page);
        // no suggestion (nothing higher is due yet): the lowest higher level is preselected
        $fresh = $this->invoice(0, ['issue_date' => '2026-11-01', 'due_date' => '2026-11-15']); // 3 days, below the 7-day threshold
        $page = $this->getQ('/dunning/new', ['invoice' => (string) $fresh])->body;
        $this->assertMatchesRegularExpression('#<option value="0" selected>Zahlungserinnerung</option>#', $page);
        // an explicitly asked level that is not allowed is shown as an error, a lower level is refused at creation too
        $this->assertSame(422, $this->getQ('/dunning/new', ['invoice' => (string) $id, 'level' => '0'])->status);
        $this->assertSame(422, $this->create($id, ['level' => '1'])->status);
        $this->assertSame(422, $this->create($id, ['level' => 'x'])->status);
        $this->assertSame(422, $this->create($id, ['level' => '7'])->status);
        $this->assertSame(1, $this->dunnings());
        // after the final level the invoice offers no "create" button and the preview redirects
        $this->createdId($this->create($id, ['level' => '3']));
        $this->assertStringNotContainsString('href="/dunning/new?invoice=' . $id . '"', $this->get('/dunning')->body);
        $this->assertRedirect('/dunning', $this->getQ('/dunning/new', ['invoice' => (string) $id]));
        $this->assertStringContainsString('bereits die letzte Mahnung', $this->get('/dunning')->body);
    }

    public function test_list_shows_days_last_letter_suggestion_colours_and_issued_letters(): void
    {
        $nine = $this->invoice($this->becker, ['issue_date' => '2026-10-20', 'due_date' => '2026-11-09']);      // 9 days -> reminder (green)
        $twentyOne = $this->invoice();                                                                          // 21 days -> 1st (orange)
        $thirtyEight = $this->invoice(0, ['issue_date' => '2026-09-20', 'due_date' => '2026-10-11'], 0, 108193); // 38 days -> 2nd (red)
        $this->svc()->createDunning($thirtyEight, DunningLevel::First, false, false, false);
        $this->login();
        $page = $this->get('/dunning')->body;
        $text = $this->plain($page);
        $this->assertSame([$thirtyEight, $twentyOne, $nine], array_map('intval', $this->grab('/data-overdue-row="(\d+)"/', $page)), 'due date ascending');
        $this->assertStringContainsString('class="sugg dot-ok" data-suggestion="0"', $page);
        $this->assertStringContainsString('class="sugg dot-soon" data-suggestion="1"', $page);
        $this->assertStringContainsString('class="sugg dot-late" data-suggestion="2"', $page);
        foreach (['1.428,00 €', '28.10.2026', 'Frau K. Becker', 'Nordlicht Werbung UG'] as $needle) {
            $this->assertStringContainsString($needle, $text, $needle);
        }
        $this->assertMatchesRegularExpression('#data-overdue-row="' . $thirtyEight . '".*?1\. Mahnung<small class="cell-sub">18\.11\.2026</small>#s', $page);
        $this->assertMatchesRegularExpression('#data-overdue-row="' . $twentyOne . '">.*?<span class="num" data-days>21</span><small class="cell-sub">fällig am 28\.10\.2026</small>#s', $page);
        $this->assertStringContainsString('href="/dunning/new?invoice=' . $twentyOne . '"', $page);
        // issued letters
        $this->assertMatchesRegularExpression('#data-dunning-row="\d+".*?MA-2611-001.*?RE-2026-003.*?1\. Mahnung#s', $page);
        $this->assertStringContainsString('1.287,50 €', $text, 'claim of the issued letter (108193 net + 19 % = 1.287,50)');
        // search narrows both lists, special characters never throw
        $this->assertSame([$nine], array_map('intval', $this->grab('/data-overdue-row="(\d+)"/', $this->getQ('/dunning', ['q' => 'becker'])->body)));
        foreach (["o'brien", 'a--b', '[x]', '#1', '%', '_', '<script>'] as $q) {
            $r = $this->getQ('/dunning', ['q' => $q]);
            $this->assertSame(200, $r->status, $q);
        }
        $this->assertSame(200, $this->getQ('/dunning', ['q' => ['x'], 'page' => ['1']])->status);
    }

    public function test_list_pagination_and_empty_states(): void
    {
        $this->login();
        $empty = $this->get('/dunning')->body;
        $this->assertStringContainsString('data-empty="overdue"', $empty);
        $this->assertStringContainsString('Nichts überfällig', $empty);
        $this->assertStringContainsString('Noch keine Mahnungen ausgestellt.', $empty);
        for ($i = 0; $i < 27; $i++) {
            $this->invoice(0, ['issue_date' => '2026-09-01', 'due_date' => sprintf('2026-10-%02d', $i + 1)]);
        }
        $p1 = $this->get('/dunning')->body;
        $this->assertCount(25, $this->grab('/data-overdue-row="(\d+)"/', $p1));
        $this->assertStringContainsString('href="/dunning?page=2"', $p1);
        $this->assertCount(2, $this->grab('/data-overdue-row="(\d+)"/', $this->getQ('/dunning', ['page' => '2'])->body));
        $this->assertCount(2, $this->grab('/data-overdue-row="(\d+)"/', $this->getQ('/dunning', ['page' => '99'])->body), 'clamped to the last page');
    }

    /** @return list<string> */
    private function grab(string $re, string $s): array
    {
        preg_match_all($re, $s, $m);
        return $m[1];
    }

    // ---- refusals ----

    public function test_ineligible_invoices_redirect_with_a_flash_and_write_nothing(): void
    {
        $dueToday = $this->invoice(0, ['issue_date' => '2026-11-04', 'due_date' => '2026-11-18']);
        $paid = $this->invoice();
        $this->pay($paid, 142800);
        $credited = $this->invoice();
        $this->svc()->issue($this->svc()->createCorrection($credited), new \DateTimeImmutable('@' . $this->now));
        $draft = $this->svc()->createDraft('invoice', ['customer_id' => $this->nordlicht, 'issue_date' => '2026-09-01', 'due_date' => '2026-09-15',
            'lines' => [['name' => 'x', 'unit' => 'flat', 'qty_milli' => 1000, 'unit_minor' => 100, 'discount_bp' => 0, 'vat_bp' => 1900]]]);
        $this->login();
        $cases = [$dueToday => 'noch nicht überfällig', $paid => 'bereits bezahlt', $credited => 'vollständig korrigiert', $draft => 'Nur ausgestellte Rechnungen'];
        foreach ($cases as $id => $message) {
            $this->assertRedirect('/dunning', $this->getQ('/dunning/new', ['invoice' => (string) $id]));
            $this->assertStringContainsString($message, $this->get('/dunning')->body, "GET $id");
            $this->assertRedirect('/dunning', $this->create($id, ['level' => '0', 'interest' => '1']));
            $this->assertStringContainsString($message, $this->get('/dunning')->body, "POST $id");
        }
        $this->assertSame([0, 0], [$this->dunnings(), $this->counter()]);
    }

    public function test_offer_credit_note_dunning_foreign_and_unknown_ids_are_identical_404(): void
    {
        $inv = $this->invoice();
        $offer = $this->svc()->createDraft('offer', ['customer_id' => $this->nordlicht, 'issue_date' => '2026-09-01',
            'lines' => [['name' => 'x', 'unit' => 'flat', 'qty_milli' => 1000, 'unit_minor' => 100, 'discount_bp' => 0, 'vat_bp' => 1900]]]);
        $this->svc()->issue($offer, new \DateTimeImmutable('@' . $this->now));
        $cn = $this->svc()->createCorrection($inv);
        $dunning = $this->svc()->createDunning($inv, DunningLevel::First, false, false, false);
        $foreign = $this->invoice($this->customer($this->companyB, ['name' => 'Geheim GmbH']), [], $this->companyB);
        $foreignDunning = $this->svc($this->companyB)->createDunning($foreign, DunningLevel::Final, false, false, false);
        $this->login();
        $strip = fn (string $b, string $id): string => (string) preg_replace('/name="back" value="[^"]*"|name="_csrf" value="[^"]*"/', '', $b);
        $unknownGet = $this->getQ('/dunning/new', ['invoice' => '999999']);
        $unknownPost = $this->create(999999);
        $this->assertSame([404, 404], [$unknownGet->status, $unknownPost->status]);
        foreach (['offer' => $offer, 'credit note' => $cn, 'dunning' => $dunning, 'foreign' => $foreign] as $name => $id) {
            $g = $this->getQ('/dunning/new', ['invoice' => (string) $id]);
            $this->assertSame(404, $g->status, $name);
            $this->assertSame($strip($unknownGet->body, '999999'), $strip($g->body, (string) $id), "$name identical to an unknown id");
            $p = $this->create($id, ['level' => '3']);
            $this->assertSame(404, $p->status, $name);
            $this->assertStringNotContainsString('Geheim', $g->body . $p->body);
        }
        foreach (['', 'abc', '-1', '1.5'] as $bad) {
            $this->assertSame(404, $this->getQ('/dunning/new', ['invoice' => $bad])->status, "invoice=$bad");
            $this->assertSame(404, $this->post('/dunning', ['invoice' => $bad, 'level' => '1'])->status, "POST invoice=$bad");
        }
        $this->assertSame(404, $this->getQ('/dunning/new', ['invoice' => ['1']])->status);
        // foreign dunning letter on every id route, PDF and send: identical to an unknown id
        $before = $this->doc($foreignDunning);
        foreach (array_merge($this->routes('/dunning/', (string) $foreignDunning), [['GET', "/documents/$foreignDunning/pdf"], ['GET', "/documents/$foreignDunning/send"], ['POST', "/documents/$foreignDunning/send"]]) as [$method, $path]) {
            if ($path === '/dunning/new') {
                continue;
            }
            $send = fn (string $p): Response => $method === 'GET' ? $this->get($p) : $this->post($p, ['to' => 'x@example.de', 'subject' => 's', 'body' => 'b']);
            $r = $send($path);
            $u = $send(str_replace((string) $foreignDunning, '999999', $path));
            $this->assertSame(404, $r->status, "$method $path");
            $this->assertSame($strip($u->body, '999999'), $strip($r->body, (string) $foreignDunning), "$method $path");
        }
        $this->assertSame($before, $this->doc($foreignDunning));
        $this->assertSame(1, $this->counter($this->companyB));
        $this->assertSame(1, $this->counter());
        $this->assertSame([], $this->transport->sent);
    }

    public function test_type_separation_and_dunning_rows_invisible_in_other_lists(): void
    {
        $inv = $this->invoice();
        $dunning = $this->svc()->createDunning($inv, DunningLevel::First, true, true, false);
        $offer = $this->svc()->createDraft('offer', ['customer_id' => $this->nordlicht, 'issue_date' => '2026-09-01',
            'lines' => [['name' => 'x', 'unit' => 'flat', 'qty_milli' => 1000, 'unit_minor' => 100, 'discount_bp' => 0, 'vat_bp' => 1900]]]);
        $this->svc()->issue($offer, new \DateTimeImmutable('@' . $this->now));
        $this->login();
        foreach (['/invoices/', '/offers/'] as $prefix) {
            foreach ($this->routes($prefix, (string) $dunning) as [$method, $path]) {
                if (str_contains($path, '/recalc') || str_ends_with($path, '/new')) {
                    continue;
                }
                $r = $method === 'GET' ? $this->get($path) : $this->post($path, ['status' => 'rejected', 'amount' => '1,00', 'paid_on' => '2026-11-18']);
                $this->assertSame(404, $r->status, "$method $path (a dunning id)");
            }
        }
        foreach ([$inv, $offer] as $id) {
            $this->assertSame(404, $this->get("/dunning/$id")->status, "an invoice/offer id on /dunning/{id}");
        }
        $invoices = $this->get('/invoices')->body;
        $this->assertStringNotContainsString('MA-2611-001', $invoices);
        foreach (['open', 'partial', 'overdue', 'paid', 'credited', 'issued', 'draft'] as $status) {
            $this->assertStringNotContainsString('MA-2611-001', $this->getQ('/invoices', ['status' => $status])->body, $status);
        }
        $this->assertStringNotContainsString('MA-2611-001', $this->get('/offers')->body);
        $docs = new DocumentRepo($this->db, CompanyContext::forUser($this->db, $this->userId, $this->companyA));
        $this->assertSame(1, $docs->count(['types' => ['invoice', 'credit_note']]));
        $this->assertSame(1, $docs->paymentCount([], new \DateTimeImmutable('@' . $this->now)));
        $this->assertSame(142800, $this->svc()->paymentSummary($inv, new \DateTimeImmutable('@' . $this->now))['balance_minor'], 'the letter never changes the balance');
        $this->assertSame(['0', '0', '0'], [(string) $this->doc($dunning)['net_minor'], (string) $this->doc($dunning)['vat_minor'], (string) $this->doc($dunning)['gross_minor']]);
    }

    public function test_invoice_list_status_filters_match_payment_summary(): void
    {
        $ids = ['open' => $this->invoice(0, ['issue_date' => '2026-11-10', 'due_date' => '2026-12-01']), 'overdue' => $this->invoice()];
        $ids['partial'] = $this->invoice(0, ['issue_date' => '2026-11-10', 'due_date' => '2026-12-01']);
        $this->pay($ids['partial'], 100);
        $ids['paid'] = $this->invoice();
        $this->pay($ids['paid'], 142800);
        $ids['credited'] = $this->invoice();
        $this->svc()->issue($this->svc()->createCorrection($ids['credited']), new \DateTimeImmutable('@' . $this->now));
        $this->login();
        foreach ($ids as $status => $id) {
            $summary = $this->svc()->paymentSummary($id, new \DateTimeImmutable('@' . $this->now));
            $this->assertSame($status, $summary['status']);
            $body = $this->getQ('/invoices', ['status' => $status])->body;
            preg_match_all('#href="/invoices/(\d+)"#', $body, $m);
            $this->assertSame([$id], array_values(array_unique(array_map('intval', $m[1]))), $status);
            $this->assertMatchesRegularExpression('#<span class="chip chip-[a-z]+">' . preg_quote($this->app->translator()->t('invoice.status_' . $status, 'de'), '#') . '</span>#', $body, $status);
        }
    }

    public function test_mass_assignment_only_level_and_toggles_are_read(): void
    {
        $id = $this->invoice();
        $foreign = $this->invoice($this->customer($this->companyB), [], $this->companyB);
        $this->login();
        $dunning = $this->createdId($this->create($id, [
            'level' => '1', 'company_id' => (string) $this->companyB, 'id' => '777', 'type' => 'invoice', 'status' => 'draft', 'number' => 'X-1',
            'related_document_id' => (string) $foreign, 'dunning_claim_minor' => '1', 'claim' => '1,00', 'outstanding' => '1,00', 'dunning_interest_minor' => '999999',
            'interest_minor' => '5', 'currency' => 'USD', 'lang' => 'en', 'pay_by' => '2030-01-01', 'dunning_pay_by' => '2030-01-01', 'gross_minor' => '5',
        ]));
        $d = $this->doc($dunning);
        $this->assertSame([$this->companyA, 'dunning', 'issued', 'MA-2611-001', $id, 142800, null, 'EUR', 'de', '2026-11-25', 0],
            [(int) $d['company_id'], $d['type'], $d['status'], $d['number'], (int) $d['related_document_id'], (int) $d['dunning_claim_minor'],
                $d['dunning_interest_minor'], $d['currency'], $d['lang'], $d['dunning_pay_by'], (int) $d['gross_minor']]);
        $this->assertNotSame(777, $dunning);
        // toggles count only with the exact value 1
        $inv2 = $this->invoice();
        $d2 = $this->doc($this->createdId($this->create($inv2, ['level' => '1', 'interest' => 'yes', 'flat_fee' => 'on', 'fee' => 'true'])));
        $this->assertSame([null, null, null], [$d2['dunning_interest_minor'], $d2['dunning_flat_fee_minor'], $d2['dunning_fee_minor']]);
    }

    // ---- auth, CSRF, roles, suspension ----

    public function test_unauthenticated_redirects_and_no_company_is_403_on_every_route(): void
    {
        $routes = $this->routes('/dunning', '1');
        $this->assertCount(4, $routes);
        foreach ($routes as [$method, $path]) {
            $this->assertRedirect('/login', $method === 'GET' ? $this->get($path) : $this->post($path, ['invoice' => '1', 'level' => '1']));
        }
        $other = $this->createUser('solo@example.pl');
        $this->loginFully($other['secret'], 'solo@example.pl');
        foreach ($routes as [$method, $path]) {
            $this->assertSame(403, ($method === 'GET' ? $this->get($path) : $this->post($path, ['invoice' => '1', 'level' => '1']))->status, "$method $path");
        }
        $this->assertSame(0, $this->dunnings());
    }

    public function test_post_without_csrf_is_419_and_writes_nothing(): void
    {
        $id = $this->invoice();
        $this->login();
        $this->assertSame(419, $this->post('/dunning', ['invoice' => (string) $id, 'level' => '1'], csrf: false)->status);
        $this->assertSame(419, $this->post('/dunning', ['invoice' => (string) $id, 'level' => '1', '_csrf' => 'forged'], csrf: false)->status);
        $this->assertSame([0, 0], [$this->dunnings(), $this->counter()]);
    }

    public function test_role_user_may_create_and_send(): void
    {
        $this->smtp($this->companyA);
        $this->db->exec("UPDATE company_users SET role = 'user' WHERE user_id = ?", [$this->userId]);
        $id = $this->invoice();
        $this->login();
        $this->assertSame(200, $this->get('/dunning')->status);
        $dunning = $this->createdId($this->create($id));
        $this->assertSame(200, $this->get("/dunning/$dunning")->status);
        $r = $this->post("/documents/$dunning/send", ['to' => 'buchhaltung@nordlicht.example', 'subject' => 'Mahnung', 'body' => 'Text']);
        $this->assertSame(303, $r->status);
        $this->assertCount(1, $this->transport->sent);
    }

    public function test_suspended_company_can_preview_and_view_but_not_create(): void
    {
        $id = $this->invoice();
        $done = $this->svc()->createDunning($this->invoice(), DunningLevel::First, false, false, false);
        $this->db->exec("UPDATE companies SET access_status = 'suspended' WHERE id = ?", [$this->companyA]);
        $this->login();
        $this->assertSame(200, $this->get('/dunning')->status);
        $preview = $this->getQ('/dunning/new', ['invoice' => (string) $id]);
        $this->assertSame(200, $preview->status);
        $this->assertStringContainsString('Dein Zugang ist gesperrt', $preview->body);
        $this->assertMatchesRegularExpression('#<button class="btn btn-primary" type="submit" disabled>Mahnung ausstellen</button>#', $preview->body);
        $this->assertSame(200, $this->get("/dunning/$done")->status);
        $r = $this->create($id);
        $this->assertSame(403, $r->status);
        $this->assertStringContainsString('Dein Zugang ist gesperrt', $r->body);
        $this->assertSame([1, 1], [$this->dunnings(), $this->counter()], 'no number consumed');
    }

    // ---- rendering ----

    public function test_xss_is_escaped_everywhere(): void
    {
        $evil = $this->customer($this->companyA, ['name' => '<script>alert(1)</script> & "Söhne"', 'address' => "<img src=x onerror=alert(1)>\nŁódź"]);
        $id = $this->invoice($evil);
        $this->db->exec("UPDATE companies SET name = '<b>Firma</b>' WHERE id = ?", [$this->companyA]);
        $this->login();
        $dunning = $this->createdId($this->create($id));
        foreach (['/dunning', '/dunning/new?invoice=' . $this->invoice($evil), "/dunning/$dunning", '/dunning?q=%3Cscript%3E'] as $path) {
            [$p, $qs] = array_pad(explode('?', $path, 2), 2, '');
            parse_str($qs, $query);
            $body = $this->getQ($p, $query)->body;
            $this->assertStringNotContainsString('<script>alert', $body, $path);
            $this->assertStringNotContainsString('<img src=x', $body, $path);
            $this->assertStringNotContainsString('<b>Firma</b>', $body, $path);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Söhne&quot;', $body, $path);
        }
    }

    public function test_pages_render_in_all_languages_with_nav_item(): void
    {
        $id = $this->invoice();
        $dunning = $this->svc()->createDunning($this->invoice(), DunningLevel::Second, false, false, false);
        $expect = ['de' => ['Mahnungen', 'Überfällige Rechnungen', 'Vorschau aktualisieren'], 'pl' => ['Wezwania do zapłaty', 'Faktury po terminie', 'Odśwież podgląd'],
            'en' => ['Payment reminders', 'Overdue invoices', 'Update preview']];
        $this->login();
        foreach ($expect as $lang => [$title, $list, $update]) {
            $this->db->exec('UPDATE users SET ui_lang = ?', [$lang]);
            $this->app->session()->set('lang', $lang);
            $page = $this->get('/dunning');
            $this->assertSame(200, $page->status, $lang);
            $this->assertStringContainsString($title, $page->body, $lang);
            $this->assertStringContainsString($list, $page->body, $lang);
            $this->assertMatchesRegularExpression('#<a class="nav-item[^"]*"[^>]*data-nav="dunning"[^>]*aria-current="page"|<a class="nav-item[^"]*"[^>]*aria-current="page"[^>]*data-nav="dunning"#', $page->body, "$lang: nav item active");
            $this->assertStringContainsString($update, $this->getQ('/dunning/new', ['invoice' => (string) $id])->body, $lang);
            $this->assertSame(200, $this->get("/dunning/$dunning")->status, $lang);
        }
    }

    // ---- PDF ----

    public function test_dunning_pdf_of_the_worked_example(): void
    {
        $this->requirePoppler();
        $id = $this->invoice();
        $this->login();
        $dunning = $this->createdId($this->create($id, ['level' => '1', 'interest' => '1', 'flat_fee' => '1']));
        $r = $this->get("/documents/$dunning/pdf");
        $this->assertSame(200, $r->status, substr($r->body, 0, 500));
        $this->assertSame('application/pdf', $r->headers['Content-Type']);
        $this->assertSame('inline; filename="MA-2611-001.pdf"', $r->headers['Content-Disposition']);
        $t = (string) preg_replace('/[ \t]+/', ' ', str_replace("\u{00A0}", ' ', PdfFixture::text($r->body)));
        foreach (['1. MAHNUNG', 'MA-2611-001', '18.11.2026', 'Zahlbar bis', '25.11.2026', 'Rechnung RE-2026-001 vom 14.10.2026, fällig am 28.10.2026',
            'Sehr geehrte Damen und Herren', 'Offener Betrag', '1.428,00 €', 'Verzugszinsen 10,27 % p. a. für 21 Tage', '8,44 €',
            'Verzugspauschale gemäß § 288 Abs. 5 BGB', '40,00 €', 'Gesamtforderung', '1.476,44 €', 'Bitte überweisen Sie die Gesamtforderung bis zum 25.11.2026.',
            'IBAN DE89370400440532013000', 'BIC COBADEFFXXX', 'Verwendungszweck: RE-2026-001', 'Nordlicht Werbung UG', 'Hafenstraße 3', 'Mit freundlichen Grüßen',
            'Muster Service GmbH', 'Seite 1 von 1'] as $needle) {
            $this->assertStringContainsString($needle, $t, $needle);
        }
        foreach (['Mahngebühr', 'USt 19', 'RECHNUNG '] as $absent) {
            $this->assertStringNotContainsString($absent, $t, $absent);
        }
    }

    public function test_dunning_pdf_titles_per_language_and_level_and_rows_only_when_charged(): void
    {
        $this->requirePoppler();
        $this->db->exec('UPDATE companies SET dunning_fee_minor = 500 WHERE id = ?', [$this->companyA]);
        $titles = ['de' => ['ZAHLUNGSERINNERUNG', '1. MAHNUNG', '2. MAHNUNG', 'LETZTE MAHNUNG'],
            'pl' => ['PRZYPOMNIENIE O PŁATNOŚCI', '1. WEZWANIE DO ZAPŁATY', '2. WEZWANIE DO ZAPŁATY', 'OSTATECZNE WEZWANIE DO ZAPŁATY'],
            'en' => ['PAYMENT REMINDER', '1ST REMINDER', '2ND REMINDER', 'FINAL REMINDER']];
        $reference = ['de' => 'Rechnung RE-', 'pl' => 'Faktura RE-', 'en' => 'Invoice RE-'];
        $total = ['de' => 'Gesamtforderung', 'pl' => 'Należność razem', 'en' => 'Total claim'];
        $bank = ['de' => 'Bankverbindung', 'pl' => 'Dane do przelewu', 'en' => 'Bank details'];
        $this->login();
        foreach ($titles as $lang => $levels) {
            $inv = $this->invoice(0, ['lang' => $lang]);
            foreach ($levels as $level => $title) {
                $charged = $level === 3; // the final letter with every extra, the others without
                $d = $this->svc()->createDunning($inv, DunningLevel::from($level), $charged, $charged, $charged);
                $t = (string) preg_replace('/\s+/', ' ', str_replace("\u{00A0}", ' ', PdfFixture::text($this->get("/documents/$d/pdf")->body)));
                $this->assertStringContainsString($title, $t, "$lang $level title");
                $this->assertStringContainsString($reference[$lang], $t, "$lang $level reference");
                $this->assertStringContainsString($total[$lang], $t, "$lang $level total");
                $this->assertStringContainsString('25', $t);
                $this->assertStringContainsString('DE89370400440532013000', $t, "$lang $level bank");
                $this->assertStringContainsString($this->app->translator()->t('pdf.bank_details', $lang), $t, "$lang $level bank label");
                foreach (['pdf.dunning_flat_fee', 'pdf.dunning_fee'] as $key) {
                    $label = $this->app->translator()->t($key, $lang);
                    $charged ? $this->assertStringContainsString($label, $t, "$lang $level $key") : $this->assertStringNotContainsString($label, $t, "$lang $level $key");
                }
                $interest = explode(' {rate}', $this->app->translator()->t('pdf.dunning_interest', $lang))[0];
                $charged ? $this->assertStringContainsString($interest, $t) : $this->assertStringNotContainsString($interest, $t);
                if ($level === 3) {
                    $this->assertStringContainsString(['de' => 'weitere Schritte', 'pl' => 'dalszych kroków', 'en' => 'further steps'][$lang], $t, "$lang final wording");
                }
            }
            unset($bank[$lang]);
        }
    }

    // ---- mail texts ----

    public function test_mail_preview_texts_in_pl_and_en_are_formal(): void
    {
        $this->smtp($this->companyA);
        $pl = $this->invoice($this->kowalski, ['eur_rate' => '4.2650', 'lang' => 'pl']);
        $en = $this->invoice(0, ['lang' => 'en']);
        $this->login();
        $dPl = $this->svc()->createDunning($pl, DunningLevel::Final, true, false, false);
        $dEn = $this->svc()->createDunning($en, DunningLevel::Reminder, false, false, false);
        $text = $this->plain($this->get("/documents/$dPl/send")->body);
        foreach (['Ostateczne wezwanie do zapłaty MA-2611-001 do faktury RE-2026-001 – Muster Service GmbH', 'Szanowni Państwo,', 'Należność razem: 1 436,44 zł',
            'do dnia 25.11.2026', 'Tytuł przelewu: RE-2026-001', 'Z poważaniem'] as $needle) {
            $this->assertStringContainsString($needle, $text, $needle);
        }
        $text = $this->plain($this->get("/documents/$dEn/send")->body);
        foreach (['Payment reminder MA-2611-002 for invoice RE-2026-002 – Muster Service GmbH', 'Dear Sir or Madam,', 'Total claim: €1,428.00', 'by 25/11/2026', 'Kind regards'] as $needle) {
            $this->assertStringContainsString($needle, $text, $needle);
        }
        $this->assertSame([], $this->transport->sent);
    }
}
