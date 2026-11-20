<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\Request;
use App\Core\Response;

/**
 * Invoice UI (Task 16): list, editor (create + draft), issue, issued view, payments, corrections
 * and the live-totals endpoint. The domain rules are tested in DocumentServiceTest; these tests
 * check the HTTP layer: parsing, whitelisting, isolation, CSRF, escaping and the page states.
 */
final class InvoiceControllerTest extends HttpTestCase
{
    private const BERLIN = 'Europe/Berlin';

    private int $companyA;
    private int $companyB;
    private string $secret;
    private int $de;
    private int $plValid;
    private int $plUnverified;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = (new \DateTimeImmutable('2026-11-18 10:00', new \DateTimeZone(self::BERLIN)))->getTimestamp();
        $this->companyA = $this->company('Muster Service GmbH');
        $this->companyB = $this->company('Fremd AG');
        $user = $this->createUser();
        $this->secret = $user['secret'];
        $this->addMember($user['id'], $this->companyA);
        $this->de = $this->customer($this->companyA, ['name' => 'Bauhaus Logistik GmbH', 'address' => "Hauptstraße 5\n80331 München", 'vat_id' => 'DE123456789']);
        $this->plValid = $this->customer($this->companyA, ['name' => 'Kowalski Transport Sp. z o.o.', 'country' => 'PL', 'vat_id' => 'PL1234567890',
            'default_lang' => 'pl', 'default_currency' => 'PLN', 'payment_days' => 30, 'vies_status' => 'valid', 'vies_checked_at' => '2026-11-18 08:00:00']);
        $this->plUnverified = $this->customer($this->companyA, ['name' => 'Nowak Sp. z o.o.', 'country' => 'PL', 'vat_id' => 'PL9999999999',
            'default_lang' => 'pl', 'default_currency' => 'EUR', 'vies_status' => 'unavailable']);
    }

    // ---- helpers ----

    private function company(string $name, string $status = 'active'): int
    {
        $this->db->exec(
            'INSERT INTO companies (name, address, vat_id, iban, bic, intro_text, outro_text, access_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$name, "Musterweg 1\n10115 Berlin", 'DE987654321', 'DE12345678901234567890', 'MUSTDEXX', 'Vielen Dank für deinen Auftrag.', 'Mit freundlichen Grüßen', $status]
        );
        return $this->db->lastId();
    }

    /** @param array<string, mixed> $data */
    private function customer(int $company, array $data = []): int
    {
        $row = ['company_id' => $company] + $data + ['name' => 'Kunde GmbH', 'country' => 'DE', 'is_company' => 1,
            'default_lang' => 'de', 'default_currency' => 'EUR', 'payment_days' => 14];
        $this->db->exec(
            'INSERT INTO customers (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')',
            array_values($row)
        );
        return $this->db->lastId();
    }

    /** @param array<string, string> $names */
    private function item(int $company, array $names, array $extra = []): int
    {
        $row = ['company_id' => $company] + $extra + ['type' => 'service', 'unit' => 'hour', 'price_minor' => 45000, 'currency' => 'EUR', 'vat_bp' => 1900, 'is_active' => 1];
        $this->db->exec(
            'INSERT INTO items (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')',
            array_values($row)
        );
        $id = $this->db->lastId();
        foreach ($names as $lang => $name) {
            $this->db->exec('INSERT INTO item_names (company_id, item_id, lang, name) VALUES (?, ?, ?, ?)', [$company, $id, $lang, $name]);
        }
        return $id;
    }

    private function login(string $lang = 'de'): void
    {
        $this->loginFully($this->secret);
        if ($lang !== 'de') {
            $this->db->exec('UPDATE users SET ui_lang = ?', [$lang]);
            $this->app->session()->set('lang', $lang);
        }
    }

    /** @return list<array<string, string>> the makieta lines: 2 x 450,00 @19, 1 x 60,00 @19, 5 x 12,00 @7 */
    private function makietaLines(): array
    {
        return [
            $this->line(['name' => 'Beratung / Workshop', 'qty' => '2', 'price' => '450,00']),
            $this->line(['name' => 'Anfahrtspauschale', 'qty' => '1', 'price' => '60,00', 'unit' => 'flat']),
            $this->line(['name' => 'Schulungsunterlagen', 'qty' => '5', 'price' => '12,00', 'unit' => 'piece', 'vat_bp' => '700']),
        ];
    }

    /** @return array<string, string> */
    private function line(array $over = []): array
    {
        return $over + ['name' => 'Beratung', 'description' => '', 'qty' => '1', 'unit' => 'hour', 'price' => '100,00',
            'discount' => '', 'vat_bp' => '1900', 'item_id' => ''];
    }

    /** @return array<string, mixed> editor form fields */
    private function form(int $customer, array $over = [], ?array $lines = null): array
    {
        return $over + [
            'customer_id' => (string) $customer, 'lang' => 'de', 'currency' => 'EUR', 'eur_rate' => '',
            'issue_date' => '2026-11-18', 'service_date' => '2026-11-18', 'due_date' => '2026-12-02',
            'intro_text' => 'Hallo', 'outro_text' => 'Danke', 'notes' => '',
            'lines' => $lines ?? $this->makietaLines(),
        ];
    }

    private function createDraft(int $customer, array $over = [], ?array $lines = null): int
    {
        $r = $this->post('/invoices', $this->form($customer, $over, $lines));
        $this->assertSame(303, $r->status, substr($r->body, 0, 2000));
        $this->assertMatchesRegularExpression('#^/invoices/\d+$#', $r->headers['Location']);
        return (int) substr($r->headers['Location'], strlen('/invoices/'));
    }

    /** @return array<string, mixed> */
    private function doc(int $id): array
    {
        return $this->db->one('SELECT * FROM documents WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    private function lines(int $id): array
    {
        return $this->db->all('SELECT * FROM document_lines WHERE document_id = ? ORDER BY position', [$id]);
    }

    private function issueDraft(int $id, array $extra = []): Response
    {
        $form = $this->formFromDb($id) + $extra;
        return $this->post("/invoices/$id/issue", $form);
    }

    /** The editor fields as the browser would post them back for a stored draft. */
    private function formFromDb(int $id): array
    {
        $d = $this->doc($id);
        $lines = [];
        foreach ($this->lines($id) as $l) {
            $lines[] = ['name' => $l['name'], 'description' => (string) $l['description'], 'qty' => (string) ($l['qty_milli'] / 1000),
                'unit' => $l['unit'], 'price' => number_format($l['unit_minor'] / 100, 2, ',', ''), 'discount' => '',
                'vat_bp' => (string) $l['vat_bp'], 'item_id' => (string) $l['item_id']];
        }
        $f = ['issue_date' => (string) $d['issue_date'], 'service_date' => (string) $d['service_date'], 'due_date' => (string) $d['due_date'],
            'intro_text' => (string) $d['intro_text'], 'outro_text' => (string) $d['outro_text'], 'notes' => (string) $d['notes'], 'lines' => $lines];
        if ($d['type'] !== 'credit_note') {
            $f += ['customer_id' => (string) $d['customer_id'], 'lang' => $d['lang'], 'currency' => $d['currency'], 'eur_rate' => (string) $d['eur_rate']];
        }
        return $f;
    }

    private function issued(int $customer = 0): int
    {
        $id = $this->createDraft($customer ?: $this->de);
        $r = $this->issueDraft($id);
        $this->assertSame(303, $r->status, substr($r->body, 0, 3000));
        return $id;
    }

    /** @param array<string, mixed> $data */
    private function recalc(array $data, bool $header = true, bool $body = false): Response
    {
        $server = ['REMOTE_ADDR' => self::IP, 'HTTP_ACCEPT' => 'application/json'];
        if ($header) {
            $server['HTTP_X_CSRF_TOKEN'] = $this->app->csrf()->token();
        }
        if ($body) {
            $data['_csrf'] = $this->app->csrf()->token();
        }
        return $this->app->handle(new Request('POST', '/invoices/recalc', post: $data, server: $server));
    }

    private function getQ(string $path, array $query): Response
    {
        return $this->app->handle(new Request('GET', $path, query: $query, server: ['REMOTE_ADDR' => self::IP]));
    }

    private function counts(): array
    {
        return [
            (int) $this->db->one('SELECT COUNT(*) AS c FROM documents')['c'],
            (int) $this->db->one('SELECT COUNT(*) AS c FROM document_lines')['c'],
            (int) $this->db->one('SELECT COUNT(*) AS c FROM payments')['c'],
        ];
    }

    private function plain(string $html): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $html);
    }

    // ---- brief tests ----

    public function test_recalc_returns_server_totals_for_makieta_example(): void
    {
        $this->login();
        $before = $this->counts();
        $r = $this->recalc($this->form($this->de));
        $this->assertSame(200, $r->status, $r->body);
        $this->assertStringStartsWith('application/json', $r->headers['Content-Type']);
        $j = json_decode($r->body, true);
        $this->assertSame(102000, $j['net']);
        $this->assertSame(120660, $j['gross']);
        $this->assertSame([['rate' => 1900, 'amount' => 18240], ['rate' => 700, 'amount' => 420]], array_map(
            fn (array $v): array => ['rate' => $v['rate'], 'amount' => $v['amount']],
            $j['vat']
        ));
        $this->assertSame(18660, $j['vat_eur']);
        $this->assertFalse($j['reverse_charge']);
        $this->assertSame([], $j['errors']);
        $this->assertSame('1.206,60 €', $this->plain($j['formatted']['gross']));
        $this->assertSame('1.020,00 €', $this->plain($j['formatted']['net']));
        $this->assertSame('182,40 €', $this->plain($j['vat'][0]['formatted']));
        $this->assertSame(['900,00 €', '60,00 €', '60,00 €'], array_map($this->plain(...), $j['formatted']['lines']));
        $this->assertSame($before, $this->counts(), 'recalc never persists');
    }

    public function test_selecting_pl_customer_with_valid_vies_defaults_to_reverse_charge_and_pln(): void
    {
        $this->login();
        $r = $this->getQ('/invoices/new', ['customer_id' => (string) $this->plValid]);
        $this->assertSame(200, $r->status);
        $this->assertMatchesRegularExpression('/<option value="PLN" selected>/', $r->body);
        $this->assertMatchesRegularExpression('/<select[^>]*name="lang"[^>]*>.*?<option value="pl" selected>/s', $r->body);
        $this->assertStringContainsString('value="2026-12-18"', $r->body, 'due date = today + 30 payment days');
        $this->assertStringContainsString('banner banner-ok', $r->body);
        $this->assertStringContainsString(htmlspecialchars($this->app->t('invoice.reverse_charge_note'), ENT_QUOTES), $r->body);
        $this->assertMatchesRegularExpression('/<select[^>]*id="line-0-vat"[^>]*disabled/', $r->body);
        $this->assertStringContainsString('name="eur_rate"', $r->body, 'PLN shows the rate field');

        // Saving with the defaults: reverse charge, PLN, no rate needed, VAT 0.
        $id = $this->createDraft($this->plValid, ['lang' => 'pl', 'currency' => 'PLN', 'eur_rate' => ''], [$this->line(['qty' => '2', 'price' => '450,00'])]);
        $doc = $this->doc($id);
        $this->assertSame('PLN', $doc['currency']);
        $this->assertSame(1, (int) $doc['reverse_charge']);
        $this->assertSame('reverse_charge', $doc['vat_reason']);
        $this->assertSame(90000, (int) $doc['gross_minor']);
        $this->assertSame(1900, (int) $this->lines($id)[0]['vat_bp'], 'the line keeps its rate; reverse charge zeroes the VAT');

        $j = json_decode($this->recalc($this->form($this->plValid, ['currency' => 'PLN', 'eur_rate' => '']))->body, true);
        $this->assertTrue($j['reverse_charge']);
        $this->assertSame([], $j['vat']);
        $this->assertSame(102000, $j['gross']);
    }

    public function test_unverified_vies_requires_explicit_confirmation_to_issue(): void
    {
        $this->login();
        $id = $this->createDraft($this->plUnverified);
        $this->assertSame('vies_not_confirmed', $this->doc($id)['vat_reason']);
        $page = $this->get("/invoices/$id");
        $this->assertStringContainsString('banner banner-warn', $page->body);
        $this->assertStringContainsString('href="/customers/' . $this->plUnverified . '"', $page->body);
        $this->assertStringContainsString('name="confirm_vat"', $page->body);

        $r = $this->issueDraft($id);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('banner banner-warn', $r->body);
        $this->assertStringContainsString(htmlspecialchars($this->app->t('invoice.err_confirm_vat'), ENT_QUOTES), $r->body);
        $this->assertSame('draft', $this->doc($id)['status']);
        $this->assertNull($this->doc($id)['number']);

        $r = $this->issueDraft($id, ['confirm_vat' => '1']);
        $this->assertSame(303, $r->status, substr($r->body, 0, 2000));
        $doc = $this->doc($id);
        $this->assertSame('issued', $doc['status']);
        $this->assertSame(0, (int) $doc['reverse_charge']);
        $this->assertSame(120660, (int) $doc['gross_minor'], 'German VAT applied');
    }

    public function test_issue_button_blocked_for_suspended_company(): void
    {
        $this->login();
        $id = $this->createDraft($this->de);
        $this->db->exec("UPDATE companies SET access_status = 'suspended' WHERE id = ?", [$this->companyA]);
        $this->app = $this->makeApp();

        $page = $this->get("/invoices/$id");
        $this->assertSame(200, $page->status);
        $this->assertMatchesRegularExpression('#<button[^>]*formaction="/invoices/' . $id . '/issue"[^>]*disabled#', $page->body);
        $msg = htmlspecialchars($this->app->t('invoice.suspended'), ENT_QUOTES);
        $this->assertStringContainsString($msg, $page->body);

        // Drafts still work.
        $this->assertSame(303, $this->post("/invoices/$id/save", array_merge($this->formFromDb($id), ['notes' => 'still editable']))->status);
        $this->assertSame('still editable', $this->doc($id)['notes']);
        $this->assertSame(200, $this->get('/invoices/new')->status);

        // A forced POST does not issue.
        $r = $this->issueDraft($id);
        $this->assertSame(403, $r->status);
        $this->assertStringContainsString($msg, $r->body);
        $this->assertSame('draft', $this->doc($id)['status']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS c FROM number_counters')['c']);
    }

    public function test_foreign_invoice_id_returns_404(): void
    {
        $foreignCustomer = $this->customer($this->companyB, ['name' => 'Geheim GmbH']);
        $this->db->exec("INSERT INTO documents (company_id, type, status, customer_id, buyer_name) VALUES (?, 'invoice', 'draft', ?, 'Geheim GmbH')", [$this->companyB, $foreignCustomer]);
        $foreign = $this->db->lastId();
        $this->login();
        $before = $this->db->one('SELECT * FROM documents WHERE id = ?', [$foreign]);
        $send = fn (string $method, string $path): Response => $method === 'GET'
            ? $this->get($path)
            : $this->post($path, $this->form($this->de) + ['amount' => '1,00', 'paid_on' => '2026-11-18']);
        foreach ($this->invoiceRoutes((string) $foreign) as [$method, $path]) {
            if (str_contains($path, '/recalc') || $path === '/invoices' || $path === '/invoices/new') {
                continue;
            }
            $r = $send($method, $path);
            $unknown = $send($method, str_replace((string) $foreign, '999999', $path));
            $this->assertSame(404, $r->status, "$method $path");
            $this->assertSame(str_replace('999999', (string) $foreign, $unknown->body), $r->body, "$method $path: identical to an unknown id");
            $this->assertStringNotContainsString('Geheim', $r->body);
        }
        $this->assertSame($before, $this->db->one('SELECT * FROM documents WHERE id = ?', [$foreign]));
        // recalc with a foreign document id (credit note context) is a 404 too
        $this->assertSame(404, $this->recalc($this->form($this->de) + ['document_id' => (string) $foreign])->status);
        // a foreign customer id never creates a draft
        $r = $this->post('/invoices', $this->form($foreignCustomer));
        $this->assertSame(422, $r->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS c FROM documents WHERE company_id = ?', [$this->companyA])['c']);
    }

    // ---- routes, auth, CSRF ----

    /** @return list<array{0: string, 1: string}> every /invoices route from config/routes.php, {id} replaced */
    private function invoiceRoutes(string $id): array
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
            if (str_starts_with($pattern, '/invoices')) {
                $out[] = [$method, (string) preg_replace('/\{\w+(?::[^}]*)?\}/', $id, $pattern)];
            }
        }
        $this->assertGreaterThanOrEqual(10, count($out), 'invoice routes not found');
        return $out;
    }

    public function test_unauthenticated_redirects_to_login_on_every_route(): void
    {
        foreach ($this->invoiceRoutes('1') as [$method, $path]) {
            $r = $method === 'GET' ? $this->get($path) : $this->post($path, []);
            $this->assertRedirect('/login', $r);
        }
    }

    public function test_user_without_company_gets_403(): void
    {
        $other = $this->createUser('solo@example.pl');
        $this->loginFully($other['secret'], 'solo@example.pl');
        foreach ($this->invoiceRoutes('1') as [$method, $path]) {
            $r = $method === 'GET' ? $this->get($path) : $this->post($path, []);
            $this->assertSame(403, $r->status, "$method $path");
        }
    }

    public function test_every_post_route_without_csrf_is_419(): void
    {
        $this->login();
        $id = $this->createDraft($this->de);
        $before = $this->counts();
        foreach ($this->invoiceRoutes((string) $id) as [$method, $path]) {
            if ($method !== 'POST') {
                continue;
            }
            $this->assertSame(419, $this->post($path, $this->form($this->de) + ['amount' => '1,00'], csrf: false)->status, $path);
        }
        $this->assertSame($before, $this->counts());
        $this->assertSame('draft', $this->doc($id)['status']);
    }

    public function test_recalc_csrf_header_or_body_auth_and_errors(): void
    {
        $this->assertRedirect('/login', $this->recalc($this->form($this->de)));
        $this->login();
        $before = $this->counts();
        $this->assertSame(200, $this->recalc($this->form($this->de), header: true)->status);
        $this->assertSame(200, $this->recalc($this->form($this->de), header: false, body: true)->status);
        $this->assertSame(419, $this->recalc($this->form($this->de), header: false)->status);

        $bad = $this->form($this->de, [], [$this->line(), $this->line(['price' => '12,345']), $this->line(['qty' => 'abc'])]);
        $r = $this->recalc($bad);
        $this->assertSame(422, $r->status);
        $j = json_decode($r->body, true);
        $this->assertSame($this->app->t('document.err_line_price'), $j['errors']['lines.1.unit_minor']);
        $this->assertSame($this->app->t('document.err_line_qty'), $j['errors']['lines.2.qty_milli']);
        $this->assertNull($j['gross']);

        $r = $this->recalc(['customer_id' => 'x', 'lines' => 'nope', 'currency' => ['EUR']]);
        $this->assertSame(422, $r->status);
        $this->assertArrayHasKey('lines', json_decode($r->body, true)['errors']);
        $this->assertSame(422, $this->recalc($this->form($this->de, ['currency' => 'PLN', 'eur_rate' => '']))->status, 'PLN without rate');
        $j = json_decode($this->recalc($this->form($this->de, ['currency' => 'PLN', 'eur_rate' => '4,2650']))->body, true);
        $this->assertSame(120660, $j['gross']);
        $this->assertSame(4375, $j['vat_eur'], 'round(18660 / 4.265)');
        $this->assertSame('1.206,60 PLN', $this->plain($j['formatted']['gross']));
        $this->assertSame($before, $this->counts());
    }

    // ---- create, save, issue, view ----

    public function test_happy_path_create_save_issue_view(): void
    {
        $this->login();
        $this->assertSame(200, $this->get('/invoices')->status);
        $new = $this->get('/invoices/new');
        $this->assertSame(200, $new->status);
        $this->assertStringContainsString('Bauhaus Logistik GmbH', $new->body);
        $editor = $this->getQ('/invoices/new', ['customer_id' => (string) $this->de]);
        $this->assertStringContainsString('action="/invoices"', $editor->body);
        $this->assertStringContainsString('Vielen Dank für deinen Auftrag.', $editor->body, 'company intro prefilled');

        $id = $this->createDraft($this->de);
        $doc = $this->doc($id);
        $this->assertSame('draft', $doc['status']);
        $this->assertSame('invoice', $doc['type']);
        $this->assertSame(120660, (int) $doc['gross_minor']);
        $this->assertCount(3, $this->lines($id));

        $page = $this->get("/invoices/$id");
        $this->assertSame(200, $page->status);
        $this->assertSame('no-store', $page->headers['Cache-Control']);
        $this->assertStringContainsString('action="/invoices/' . $id . '/save"', $page->body);
        $this->assertStringContainsString('value="Beratung / Workshop"', $page->body);
        $this->assertStringContainsString('1.206,60', $this->plain($page->body));
        $this->assertStringContainsString('class="paper"', $page->body);
        $this->assertStringContainsString('RE-' . '2026-001', $page->body, 'next number preview');

        $form = $this->formFromDb($id);
        $form['notes'] = 'Bitte pünktlich zahlen';
        $form['lines'][0]['qty'] = '3';
        $r = $this->post("/invoices/$id/save", $form);
        $this->assertRedirect("/invoices/$id", $r);
        $this->assertSame(303, $r->status);
        $this->assertSame('Bitte pünktlich zahlen', $this->doc($id)['notes']);
        $this->assertSame(3000, (int) $this->lines($id)[0]['qty_milli']);
        $this->assertStringContainsString($this->app->t('invoice.saved'), $this->get("/invoices/$id")->body);

        $r = $this->issueDraft($id);
        $this->assertSame(303, $r->status);
        $this->assertSame("/invoices/$id", $r->headers['Location']);
        $doc = $this->doc($id);
        $this->assertSame('issued', $doc['status']);
        $this->assertSame('RE-2026-001', $doc['number']);
        $view = $this->get("/invoices/$id");
        $this->assertSame('no-store', $view->headers['Cache-Control']);
        $this->assertStringContainsString($this->app->t('invoice.issued_as', ['number' => 'RE-2026-001']), $view->body);
        $this->assertStringNotContainsString($this->app->t('invoice.issued_as', ['number' => 'RE-2026-001']), $this->get("/invoices/$id")->body, 'flash shown once');
        $this->assertStringContainsString('Muster Service GmbH', $view->body);
        $this->assertStringContainsString('Hauptstraße 5<br>80331 München', $view->body);
        $this->assertStringContainsString('1.742,10', $this->plain($view->body));

        // Snapshot: later customer/company changes do not change the issued view.
        $this->db->exec("UPDATE customers SET name = 'Umbenannt AG' WHERE id = ?", [$this->de]);
        $this->db->exec("UPDATE companies SET name = 'Neuer Name GmbH' WHERE id = ?", [$this->companyA]);
        $this->app = $this->makeApp();
        $view = $this->get("/invoices/$id");
        $this->assertStringContainsString('Bauhaus Logistik GmbH', $view->body);
        $this->assertStringNotContainsString('Umbenannt AG', $view->body);
        $this->assertStringContainsString('RE-2026-001', $this->get('/invoices')->body);
    }

    public function test_issue_from_create_mode(): void
    {
        $this->login();
        $r = $this->post('/invoices', $this->form($this->de) + ['intent' => 'issue']);
        $this->assertSame(303, $r->status, substr($r->body, 0, 2000));
        $id = (int) substr($r->headers['Location'], strlen('/invoices/'));
        $this->assertSame('issued', $this->doc($id)['status']);
    }

    public function test_price_input_per_language_is_stored_identically(): void
    {
        foreach (['de' => '1.234,56', 'pl' => '1 234,56', 'en' => '1,234.56'] as $lang => $price) {
            $this->setUp();
            $this->login($lang);
            $id = $this->createDraft($this->de, [], [$this->line(['price' => $price, 'qty' => $lang === 'en' ? '2.5' : '2,5'])]);
            $l = $this->lines($id)[0];
            $this->assertSame(123456, (int) $l['unit_minor'], $lang);
            $this->assertSame(2500, (int) $l['qty_milli'], $lang);
            $page = $this->get("/invoices/$id");
            $this->assertSame(200, $page->status, $lang);
            $this->assertStringContainsString($lang === 'en' ? 'value="1234.56"' : 'value="1234,56"', $page->body, $lang);
        }
    }

    public function test_remove_and_add_line_rerender_without_persisting(): void
    {
        $this->login();
        $form = $this->form($this->de, ['notes' => 'typed note']);
        $before = $this->counts();
        $r = $this->post('/invoices', $form + ['remove_line' => ['1' => '1']]);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('value="Beratung / Workshop"', $r->body);
        $this->assertStringNotContainsString('value="Anfahrtspauschale"', $r->body);
        $this->assertStringContainsString('value="Schulungsunterlagen"', $r->body);
        $this->assertStringContainsString('typed note', $r->body);
        $r = $this->post('/invoices', $form + ['add_line' => '1']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('name="lines[3][name]"', $r->body);
        $this->assertStringNotContainsString('name="lines[4][name]"', $r->body);
        $this->assertSame($before, $this->counts());

        // On a draft the same buttons work through the save route (still nothing stored).
        $id = $this->createDraft($this->de);
        $before = $this->counts();
        $r = $this->post("/invoices/$id/save", $this->formFromDb($id) + ['remove_line' => ['0' => '1']]);
        $this->assertSame(200, $r->status);
        $this->assertStringNotContainsString('value="Beratung / Workshop"', $r->body);
        $this->assertSame($before, $this->counts());
        $this->assertCount(3, $this->lines($id));

        // Empty extra rows are ignored on save.
        $form = $this->formFromDb($id);
        $form['lines'][] = $this->line(['name' => '', 'qty' => '', 'price' => '']);
        $this->assertSame(303, $this->post("/invoices/$id/save", $form)->status);
        $this->assertCount(3, $this->lines($id));
    }

    public function test_add_from_catalogue_uses_document_language_and_item_values(): void
    {
        $this->login();
        $item = $this->item($this->companyA, ['de' => 'Beratung DE', 'pl' => 'Doradztwo PL'], ['unit' => 'day', 'price_minor' => 123450, 'vat_bp' => 700]);
        $inactive = $this->item($this->companyA, ['de' => 'Alt'], ['is_active' => 0]);
        $foreign = $this->item($this->companyB, ['de' => 'Fremd']);
        $editor = $this->getQ('/invoices/new', ['customer_id' => (string) $this->de]);
        $this->assertStringContainsString('Beratung DE', $editor->body);
        $this->assertStringNotContainsString('>Alt<', $editor->body);
        $this->assertStringNotContainsString('Fremd', $editor->body);

        $r = $this->post('/invoices', $this->form($this->de, ['lang' => 'pl'], [$this->line()]) + ['add_item' => '1', 'catalogue_item' => (string) $item]);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('name="lines[1][name]" value="Doradztwo PL"', $r->body);
        $this->assertStringContainsString('name="lines[1][price]" value="1234,50"', $r->body);
        $this->assertMatchesRegularExpression('/name="lines\[1\]\[unit\]".*?<option value="day" selected>/s', $r->body);
        $this->assertStringContainsString('name="lines[1][item_id]" value="' . $item . '"', $r->body);

        foreach ([$inactive, $foreign, 999999] as $bad) {
            $r = $this->post('/invoices', $this->form($this->de, [], [$this->line()]) + ['add_item' => '1', 'catalogue_item' => (string) $bad]);
            $this->assertSame(422, $r->status);
            $this->assertStringNotContainsString('name="lines[1][name]"', $r->body);
            $this->assertStringNotContainsString('Fremd', $r->body);
        }

        // Saved with the item link.
        $form = $this->form($this->de, [], [$this->line(['name' => 'Beratung DE', 'item_id' => (string) $item])]);
        $id = $this->createDraft($this->de, [], $form['lines']);
        $this->assertSame($item, (int) $this->lines($id)[0]['item_id']);
    }

    public function test_catalogue_item_in_other_currency_gives_a_hint_not_a_wrong_price(): void
    {
        $this->login();
        $item = $this->item($this->companyA, ['de' => 'Zloty-Leistung'], ['price_minor' => 50000, 'currency' => 'PLN']);
        $r = $this->post('/invoices', $this->form($this->de, [], [$this->line()]) + ['add_item' => '1', 'catalogue_item' => (string) $item]);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('name="lines[1][name]" value="Zloty-Leistung"', $r->body);
        $this->assertStringContainsString('name="lines[1][price]" value=""', $r->body);
        $hint = $this->app->t('invoice.catalogue_currency', ['price' => "500,00\u{00A0}PLN", 'currency' => 'EUR']);
        $this->assertStringContainsString($this->plain(htmlspecialchars($hint, ENT_QUOTES)), $this->plain($r->body));
    }

    public function test_422_rerender_keeps_typed_values_and_shows_field_errors(): void
    {
        $this->login();
        $lines = [$this->line(['name' => 'Gut', 'qty' => '2,5']), $this->line(['name' => 'Kaputt', 'price' => '12,345']), $this->line(['name' => '', 'qty' => '1', 'price' => '5,00'])];
        $before = $this->counts();
        $r = $this->post('/invoices', $this->form($this->de, ['notes' => 'Meine "Notiz"', 'due_date' => '2026-02-30'], $lines));
        $this->assertSame(422, $r->status);
        $this->assertSame($before, $this->counts());
        $this->assertStringContainsString('value="2,5"', $r->body);
        $this->assertStringContainsString('value="12,345"', $r->body);
        $this->assertStringContainsString('Meine &quot;Notiz&quot;', $r->body);
        $this->assertStringContainsString(htmlspecialchars($this->app->t('document.err_line_price'), ENT_QUOTES), $r->body);
        $this->assertStringContainsString('id="line-1-price-error"', $r->body);
        $this->assertMatchesRegularExpression('/aria-invalid="true"[^>]*aria-describedby="line-1-price-error"|aria-describedby="line-1-price-error"[^>]*aria-invalid="true"/', $r->body);

        // service-level errors (after parsing succeeded) are mapped the same way
        $r = $this->post('/invoices', $this->form($this->de, ['due_date' => '2026-02-30'], [$this->line(), $this->line(['name' => ''])]));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('id="line-1-name-error"', $r->body);
        $this->assertStringContainsString(htmlspecialchars($this->app->t('document.err_date'), ENT_QUOTES), $r->body);

        // draft: invalid save keeps the stored draft unchanged
        $id = $this->createDraft($this->de);
        $form = $this->formFromDb($id);
        $form['lines'][1]['price'] = 'abc';
        $form['notes'] = 'neu';
        $r = $this->post("/invoices/$id/save", $form);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('value="abc"', $r->body);
        $this->assertStringContainsString('id="line-1-price-error"', $r->body);
        $this->assertNotSame('neu', $this->doc($id)['notes']);
    }

    public function test_whitelist_ignores_forbidden_fields(): void
    {
        $this->login();
        $id = $this->createDraft($this->de, ['company_id' => (string) $this->companyB, 'id' => '777', 'type' => 'offer', 'status' => 'issued',
            'number' => 'HACK-1', 'gross_minor' => '1', 'reverse_charge' => '1', 'vat_reason' => 'reverse_charge', 'vat_note_key' => 'x']);
        $doc = $this->doc($id);
        $this->assertSame($this->companyA, (int) $doc['company_id']);
        $this->assertNotSame(777, $id);
        $this->assertSame('invoice', $doc['type']);
        $this->assertSame('draft', $doc['status']);
        $this->assertNull($doc['number']);
        $this->assertSame(120660, (int) $doc['gross_minor']);
        $this->assertSame(0, (int) $doc['reverse_charge']);
        $this->assertSame('domestic', $doc['vat_reason']);
    }

    public function test_xss_matrix_is_escaped_everywhere(): void
    {
        $evil = '<script>alert(1)</script> & "q" \'s\' Łódź Größe';
        $this->login();
        $c = $this->customer($this->companyA, ['name' => $evil, 'address' => "<b>Straße</b>\n<i>2</i>"]);
        $id = $this->createDraft($c, ['notes' => $evil, 'intro_text' => $evil], [$this->line(['name' => $evil, 'description' => $evil])]);
        $pages = [
            'editor' => $this->get("/invoices/$id"),
            'list' => $this->get('/invoices'),
            'search' => $this->getQ('/invoices', ['q' => $evil]),
            'new' => $this->get('/invoices/new'),
            'rerender' => $this->post("/invoices/$id/save", $this->formFromDb($id) + ['add_line' => '1']),
        ];
        $this->assertSame(303, $this->issueDraft($id)->status);
        $this->post("/invoices/$id/payments", ['amount' => '1,00', 'paid_on' => '2026-11-18', 'note' => $evil]);
        $pages['view'] = $this->get("/invoices/$id");
        $pages['payment_error'] = $this->post("/invoices/$id/payments", ['amount' => 'x', 'paid_on' => '2026-11-18', 'note' => $evil]);
        foreach ($pages as $name => $r) {
            $this->assertStringNotContainsString('<script>alert(1)', $r->body, $name);
            $this->assertStringNotContainsString('<b>Straße', $r->body, $name);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;q&quot; &#039;s&#039; Łódź Größe', $r->body, $name);
        }
        $this->assertStringContainsString('&lt;b&gt;Straße&lt;/b&gt;<br>&lt;i&gt;2&lt;/i&gt;', $pages['view']->body);
    }

    // ---- issued documents ----

    public function test_issued_document_has_no_edit_controls_and_refuses_changes(): void
    {
        $this->login();
        $id = $this->issued();
        $view = $this->get("/invoices/$id");
        $this->assertSame(200, $view->status);
        $this->assertStringNotContainsString("/invoices/$id/save", $view->body);
        $this->assertStringNotContainsString("/invoices/$id/delete", $view->body);
        $this->assertStringNotContainsString("/invoices/$id/issue", $view->body);
        $this->assertStringNotContainsString('name="lines[', $view->body);
        $this->assertStringContainsString('data-block="document_actions"', $view->body);
        $before = $this->doc($id);
        $lines = $this->lines($id);
        foreach (['save', 'delete', 'issue'] as $action) {
            $form = $this->form($this->de, ['notes' => 'hack'], [$this->line(['price' => '1,00'])]);
            $r = $this->post("/invoices/$id/$action", $form);
            $this->assertSame(303, $r->status, $action);
            $this->assertSame("/invoices/$id", $r->headers['Location']);
        }
        $this->assertSame($before, $this->doc($id));
        $this->assertSame($lines, $this->lines($id));
        $this->assertStringContainsString($this->app->t('invoice.locked'), $this->get("/invoices/$id")->body);
    }

    public function test_delete_draft(): void
    {
        $this->login();
        $id = $this->createDraft($this->de);
        $this->assertStringContainsString("action=\"/invoices/$id/delete\"", $this->get("/invoices/$id")->body);
        $r = $this->post("/invoices/$id/delete");
        $this->assertRedirect('/invoices', $r);
        $this->assertNull($this->db->one('SELECT id FROM documents WHERE id = ?', [$id]));
        $this->assertSame(404, $this->get("/invoices/$id")->status);
    }

    public function test_payments_flow(): void
    {
        $this->login();
        $draft = $this->createDraft($this->de);
        $this->assertStringNotContainsString("/invoices/$draft/payments", $this->get("/invoices/$draft")->body, 'no payment form on drafts');
        $r = $this->post("/invoices/$draft/payments", ['amount' => '10,00', 'paid_on' => '2026-11-18']);
        $this->assertSame(303, $r->status);
        $this->assertSame(0, $this->counts()[2], 'payment on a draft rejected');

        $id = $this->issued();
        $view = $this->get("/invoices/$id");
        $this->assertStringContainsString("action=\"/invoices/$id/payments\"", $view->body);
        $this->assertStringContainsString('value="2026-11-18"', $view->body, 'paid_on defaults to Berlin today');
        $this->assertStringContainsString('chip chip-sent', $view->body, 'open');

        $r = $this->post("/invoices/$id/payments", ['amount' => '1.000,00', 'paid_on' => '2026-11-18', 'note' => 'Teil 1']);
        $this->assertRedirect("/invoices/$id", $r);
        $view = $this->get("/invoices/$id");
        $this->assertStringContainsString('Teil 1', $view->body);
        $this->assertStringContainsString('chip chip-soon', $view->body, 'partial');
        $this->assertStringContainsString('206,60', $this->plain($view->body), 'balance');

        $r = $this->post("/invoices/$id/payments", ['amount' => '500,00', 'paid_on' => '2026-11-18']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString(htmlspecialchars($this->app->t('document.err_overpayment'), ENT_QUOTES), $r->body);
        $this->assertStringContainsString('value="500,00"', $r->body);
        $r = $this->post("/invoices/$id/payments", ['amount' => '1,00', 'paid_on' => '2026-13-01']);
        $this->assertSame(422, $r->status);
        $this->assertSame(1, $this->counts()[2]);

        $this->assertRedirect("/invoices/$id", $this->post("/invoices/$id/payments", ['amount' => '206,60', 'paid_on' => '2026-11-19']));
        $view = $this->get("/invoices/$id");
        $this->assertStringNotContainsString("action=\"/invoices/$id/payments\"", $view->body, 'form gone after full payment');
        $this->assertStringContainsString('chip chip-ok', $view->body);
        $this->assertStringContainsString($this->app->t('invoice.status_paid'), $view->body);
    }

    public function test_correction_flow(): void
    {
        $this->login();
        $id = $this->issued();
        $view = $this->get("/invoices/$id");
        $this->assertStringContainsString("action=\"/invoices/$id/correct\"", $view->body);
        $r = $this->post("/invoices/$id/correct");
        $this->assertSame(303, $r->status);
        $cn = (int) substr($r->headers['Location'], strlen('/invoices/'));
        $doc = $this->doc($cn);
        $this->assertSame('credit_note', $doc['type']);
        $this->assertSame($id, (int) $doc['related_document_id']);

        $editor = $this->get("/invoices/$cn");
        $this->assertSame(200, $editor->status);
        $this->assertStringContainsString($this->app->t('invoice.type_credit_note'), $editor->body);
        $this->assertStringNotContainsString('id="f-customer"', $editor->body, 'customer locked');
        $this->assertStringNotContainsString('id="f-currency"', $editor->body, 'currency locked');
        $this->assertStringNotContainsString('id="f-lang"', $editor->body, 'language locked');
        $this->assertStringContainsString('href="/invoices/' . $id . '"', $editor->body, 'link to the original');

        // Partial correction: one line of 60,00 @19.
        $form = $this->formFromDb($cn);
        $form['lines'] = [$form['lines'][1]];
        $form['customer_id'] = (string) $this->plValid; // ignored for credit notes
        $this->assertSame(303, $this->post("/invoices/$cn/save", $form)->status);
        $this->assertSame((int) $this->de, (int) $this->doc($cn)['customer_id']);
        $r = $this->issueDraft($cn);
        $this->assertSame(303, $r->status, substr($r->body, 0, 2000));
        $doc = $this->doc($cn);
        $this->assertSame('issued', $doc['status']);
        $this->assertSame(-7140, (int) $doc['gross_minor']);
        $cnView = $this->plain($this->get("/invoices/$cn")->body);
        $this->assertStringContainsString('-71,40', $cnView);
        $this->assertStringContainsString('RE-2026-001', $cnView, 'credit note view links the invoice number');

        $view = $this->plain($this->get("/invoices/$id")->body);
        $this->assertStringContainsString((string) $doc['number'], $view, 'invoice lists its credit note');
        $this->assertStringContainsString('1.135,20', $view, 'balance reduced by the credit note');

        // Full correction of the rest -> credited, button gone.
        $cn2 = (int) substr($this->post("/invoices/$id/correct")->headers['Location'], strlen('/invoices/'));
        $form = $this->formFromDb($cn2);
        $form['lines'][1]['qty'] = '0,001';
        $form['lines'][1]['price'] = '0,00';
        $this->assertSame(303, $this->post("/invoices/$cn2/save", $form)->status);
        $this->assertSame(303, $this->issueDraft($cn2)->status);
        $view = $this->get("/invoices/$id");
        $this->assertStringContainsString($this->app->t('invoice.status_credited'), $view->body);
        $this->assertStringNotContainsString("action=\"/invoices/$id/correct\"", $view->body);
        $this->assertStringNotContainsString("action=\"/invoices/$id/payments\"", $view->body);
        $list = $this->get('/invoices');
        $this->assertStringContainsString($this->app->t('invoice.type_credit_note'), $list->body);
    }

    // ---- list ----

    public function test_list_statuses_filters_pagination(): void
    {
        $this->login();
        $this->assertStringContainsString($this->app->t('invoice.list_empty'), $this->get('/invoices')->body);
        $draft = $this->createDraft($this->de, [], [$this->line(['price' => '10,00'])]);
        $open = $this->issued();
        $this->db->exec("UPDATE documents SET due_date = '2026-11-01' WHERE id = ?", [$open]);
        $list = $this->get('/invoices');
        $this->assertStringContainsString('chip chip-draft', $list->body);
        $this->assertStringContainsString('chip chip-late', $list->body);
        $this->assertStringContainsString($this->app->t('invoice.draft_number'), $list->body);
        $this->assertStringContainsString('href="/invoices/' . $draft . '"', $list->body);
        $this->assertStringContainsString('1.206,60 €', $this->plain($list->body));

        $this->assertStringNotContainsString('href="/invoices/' . $open . '"', $this->getQ('/invoices', ['status' => 'draft'])->body);
        $this->assertStringContainsString('href="/invoices/' . $open . '"', $this->getQ('/invoices', ['status' => 'overdue'])->body);
        $this->assertStringNotContainsString('href="/invoices/' . $draft . '"', $this->getQ('/invoices', ['status' => 'overdue'])->body);
        $this->assertStringNotContainsString('href="/invoices/' . $open . '"', $this->getQ('/invoices', ['type' => 'credit_note'])->body);
        $this->assertStringContainsString('href="/invoices/' . $open . '"', $this->getQ('/invoices', ['q' => 'RE-2026'])->body);
        foreach (["o'brien", 'a--b', '[x]', '#1', '%', '_', '\\', 'ü', str_repeat('x', 500)] as $q) {
            $this->assertSame(200, $this->getQ('/invoices', ['q' => $q])->status, $q);
        }
        foreach (['bogus', "' OR 1=1", ''] as $v) {
            $this->assertSame(200, $this->getQ('/invoices', ['status' => $v, 'type' => $v, 'page' => $v])->status, $v);
        }
        $this->assertSame(200, $this->app->handle(new Request('GET', '/invoices', query: ['status' => ['x'], 'page' => ['1']], server: ['REMOTE_ADDR' => self::IP]))->status);

        // 27 more drafts -> 29 rows, 2 pages of 25
        for ($i = 0; $i < 27; $i++) {
            $this->db->exec("INSERT INTO documents (company_id, type, status, customer_id, buyer_name, issue_date) VALUES (?, 'invoice', 'draft', ?, 'Bulk', '2026-01-01')", [$this->companyA, $this->de]);
        }
        $p1 = $this->get('/invoices');
        $this->assertSame(25, substr_count($p1->body, 'data-doc-row'));
        $this->assertStringContainsString('href="/invoices?page=2"', $p1->body);
        $p2 = $this->getQ('/invoices', ['page' => '2']);
        $this->assertSame(4, substr_count($p2->body, 'data-doc-row'));
        $this->assertSame(4, substr_count($this->getQ('/invoices', ['page' => '99'])->body, 'data-doc-row'), 'clamped to the last page');
        $this->assertStringContainsString('href="/invoices?status=draft&amp;page=2"', $this->getQ('/invoices', ['status' => 'draft'])->body);
        // other company's documents are not listed
        $this->db->exec("INSERT INTO documents (company_id, type, status, buyer_name) VALUES (?, 'invoice', 'draft', 'Geheim GmbH')", [$this->companyB]);
        $this->assertSame(0, substr_count($this->getQ('/invoices', ['q' => 'Geheim'])->body, 'data-doc-row'));
        // offers are not listed here
        $this->db->exec("INSERT INTO documents (company_id, type, status, buyer_name) VALUES (?, 'offer', 'draft', 'Angebotskunde')", [$this->companyA]);
        $offer = $this->db->lastId();
        $this->assertSame(0, substr_count($this->getQ('/invoices', ['q' => 'Angebotskunde'])->body, 'data-doc-row'));
        $this->assertSame(404, $this->get("/invoices/$offer")->status, 'offers are not edited here (Task 19)');
    }

    public function test_nav_item_enabled_and_pages_render_in_all_languages(): void
    {
        foreach (['de', 'pl', 'en'] as $lang) {
            $this->setUp();
            $this->login($lang);
            $id = $this->createDraft($this->de);
            foreach (['/invoices', '/invoices/new', "/invoices/$id"] as $path) {
                $r = $this->get($path);
                $this->assertSame(200, $r->status, "$lang $path");
                $this->assertStringContainsString('<html lang="' . $lang . '"', $r->body);
                $this->assertStringContainsString('data-nav="new_invoice"', $r->body);
                $this->assertStringContainsString('aria-current="page"', $r->body);
            }
            $this->assertStringContainsString(['de' => 'Rechnungskorrektur', 'pl' => 'Faktura korygująca', 'en' => 'Credit note'][$lang], $this->app->t('invoice.type_credit_note'));
        }
    }

    public function test_editor_has_accessible_labels_and_live_totals_hooks(): void
    {
        $this->login();
        $id = $this->createDraft($this->de);
        $body = $this->get("/invoices/$id")->body;
        preg_match_all('/<(?:input|select|textarea)\b[^>]*>/', $body, $m);
        foreach ($m[0] as $tag) {
            if (preg_match('/type="(hidden|search)"/', $tag) || !preg_match('/\bid="([^"]+)"/', $tag, $idm)) {
                if (!preg_match('/type="(hidden|search)"/', $tag)) {
                    $this->assertMatchesRegularExpression('/aria-label="[^"]+"/', $tag, "unlabelled control: $tag");
                }
                continue;
            }
            $this->assertTrue(
                str_contains($body, 'for="' . $idm[1] . '"') || preg_match('/aria-label(ledby)?="[^"]+"/', $tag) === 1,
                "no label for #{$idm[1]}"
            );
        }
        $this->assertStringContainsString('aria-live="polite"', $body);
        $this->assertStringContainsString('src="/assets/invoice.js', $body);
        $this->assertStringContainsString('data-recalc="/invoices/recalc"', $body);
        $this->assertStringContainsString('name="document_id" value="' . $id . '"', $body);
    }
}
