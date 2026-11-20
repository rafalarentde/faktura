<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Response;
use FastRoute\RouteCollector;

/**
 * Content-Security-Policy header and the matching template rule: no inline scripts, inline
 * styles or inline event handlers on any page the app renders.
 */
final class ContentSecurityTest extends HttpTestCase
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'";

    public function test_csp_header_on_html_pages(): void
    {
        $r = $this->get('/login');
        $this->assertSame(self::CSP, $r->headers['Content-Security-Policy'] ?? null);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $r->headers['X-Frame-Options']);
        $this->assertSame(self::CSP, $this->get('/nope')->headers['Content-Security-Policy'] ?? null);
    }

    public function test_existing_csp_is_kept_and_json_gets_none(): void
    {
        $this->app->routes(function (App $app, RouteCollector $r): void {
            $r->addRoute('GET', '/_t/own', $app->open(fn (): Response => Response::html('x')->withHeader('Content-Security-Policy', "default-src 'none'")));
            $r->addRoute('GET', '/_t/json', $app->open(fn (): Response => Response::json(['a' => 1])));
        });
        $this->assertSame("default-src 'none'", $this->get('/_t/own')->headers['Content-Security-Policy']);
        $this->assertArrayNotHasKey('Content-Security-Policy', $this->get('/_t/json')->headers);
    }

    /** Every page rendered in the suite's flows: pre-login, 2FA, setup, dashboard, chooser, admin, errors. */
    public function test_rendered_pages_have_no_inline_markup(): void
    {
        $pages = [];
        $pages['login'] = $this->get('/login');
        $pages['not_found'] = $this->get('/does-not-exist');

        // 2FA setup (new user) and backup codes.
        $setup = $this->createUser('neu@example.pl', totp: false);
        $this->loginPassword('neu@example.pl');
        $pages['2fa_setup'] = $this->get('/2fa/setup');
        $secret = $this->app->auth()->pendingTotpSecret($setup['id']);
        $pages['2fa_codes'] = $this->post('/2fa/setup', ['code' => $this->code($secret)]);
        $pages['no_company'] = $this->get('/');
        $this->post('/logout');

        // Second factor page, then the shell for an admin with two companies.
        $u = $this->createUser('admin@example.com');
        $this->db->exec('UPDATE users SET is_admin = 1 WHERE id = ?', [$u['id']]);
        $a = $this->createCompany('Alpha');
        $this->addMember($u['id'], $a);
        $this->addMember($u['id'], $this->createCompany('Beta'));
        $this->now += 60; // fresh TOTP step
        $this->loginPassword('admin@example.com');
        $pages['login_2fa'] = $this->get('/login/2fa');
        $this->post('/login/2fa', ['code' => $this->code($u['secret'])]);
        $pages['chooser'] = $this->get('/company/choose');
        $this->post('/company/switch', ['company_id' => (string) $a]);
        $pages['dashboard'] = $this->get('/');
        $pages['admin_list'] = $this->get('/admin/companies');
        $pages['admin_new'] = $this->get('/admin/companies/new');
        $pages['admin_show'] = $this->get("/admin/companies/$a");
        $pages['forbidden_switch'] = $this->post('/company/switch', ['company_id' => '999']);
        $pages['bad_lang'] = $this->post('/lang', ['lang' => 'xx']);

        $this->app = $this->makeApp(['promo_enabled' => true]);
        $pages['dashboard_promo'] = $this->get('/');

        foreach ($pages as $name => $r) {
            $this->assertStringContainsString('<html', $r->body, $name);
            $this->assertSame(self::CSP, $r->headers['Content-Security-Policy'] ?? null, $name);
            $this->assertNoInlineMarkup($name, $r->body);
        }
        $this->assertSame(200, $pages['dashboard']->status);
        $this->assertSame(200, $pages['chooser']->status);
        $this->assertSame(200, $pages['admin_show']->status);
        $this->assertSame(403, $pages['no_company']->status);
    }

    /** Invoice pages (Task 16): list, step 1, editor states, re-render, issued view, credit note, JSON. */
    public function test_invoice_pages_have_no_inline_markup(): void
    {
        $u = $this->createUser('inv@example.com');
        $c = $this->createCompany('Inv GmbH');
        $this->db->exec("UPDATE companies SET address = 'Firmenweg 1', vat_id = 'DE987654321' WHERE id = ?", [$c]);
        $this->addMember($u['id'], $c);
        $this->db->exec("INSERT INTO customers (company_id, name, address, country, is_company, vat_id, vies_status, default_currency) VALUES (?, 'Kowalski', 'ul. Długa 12', 'PL', 1, 'PL1234567890', 'valid', 'PLN')", [$c]);
        $pl = $this->db->lastId();
        $this->db->exec("INSERT INTO customers (company_id, name, address, country, is_company, vat_id) VALUES (?, 'Nowak', 'ul. Krótka 1', 'PL', 1, 'PL999')", [$c]);
        $unverified = $this->db->lastId();
        $this->loginFully($u['secret'], 'inv@example.com');
        $line = ['name' => 'Beratung', 'description' => '', 'qty' => '2', 'unit' => 'hour', 'price' => '450,00', 'discount' => '', 'vat_bp' => '1900', 'item_id' => ''];
        $form = fn (int $customer, array $over = []): array => $over + ['customer_id' => (string) $customer, 'lang' => 'de', 'currency' => 'EUR',
            'eur_rate' => '', 'issue_date' => '', 'service_date' => '', 'due_date' => '', 'intro_text' => '', 'outro_text' => '', 'notes' => '', 'lines' => [$line]];

        $pages = [];
        $pages['invoice_list_empty'] = $this->get('/invoices');
        $pages['invoice_new'] = $this->get('/invoices/new');
        $pages['invoice_new_rc'] = $this->app->handle(new \App\Core\Request('GET', '/invoices/new', query: ['customer_id' => (string) $pl], server: ['REMOTE_ADDR' => self::IP]));
        $pages['invoice_422'] = $this->post('/invoices', $form($pl, ['currency' => 'PLN', 'lines' => [['price' => 'x'] + $line]]));
        $pages['invoice_add_line'] = $this->post('/invoices', $form($pl) + ['add_line' => '1']);
        $created = $this->post('/invoices', $form($unverified));
        $id = (int) substr($created->headers['Location'] ?? '', strlen('/invoices/'));
        $pages['invoice_draft_vies'] = $this->get("/invoices/$id");
        $pages['invoice_issue_confirm'] = $this->post("/invoices/$id/issue", $form($unverified));
        $this->post("/invoices/$id/issue", $form($unverified) + ['confirm_vat' => '1']);
        $pages['invoice_issued'] = $this->get("/invoices/$id");
        $pages['invoice_payment_error'] = $this->post("/invoices/$id/payments", ['amount' => 'x', 'paid_on' => '']);
        $cn = (int) substr($this->post("/invoices/$id/correct")->headers['Location'] ?? '', strlen('/invoices/'));
        $pages['credit_note_draft'] = $this->get("/invoices/$cn");
        $pages['invoice_list'] = $this->get('/invoices');

        foreach ($pages as $name => $r) {
            $this->assertStringContainsString('<html', $r->body, $name);
            $this->assertSame(self::CSP, $r->headers['Content-Security-Policy'] ?? null, $name);
            $this->assertNoInlineMarkup($name, $r->body);
        }
        $this->assertSame(200, $pages['invoice_issued']->status);
        $this->assertSame(200, $pages['credit_note_draft']->status);
        $this->assertSame(422, $pages['invoice_issue_confirm']->status);
        $this->assertStringContainsString('src="/assets/invoice.js', $pages['invoice_draft_vies']->body);
        $json = $this->post('/invoices/recalc', $form($pl));
        $this->assertArrayNotHasKey('Content-Security-Policy', $json->headers);
    }

    /** Offer pages (Task 19): list, step 1, editor states, re-render, issued view with status buttons, converted state, JSON. */
    public function test_offer_pages_have_no_inline_markup(): void
    {
        $u = $this->createUser('off@example.com');
        $c = $this->createCompany('Off GmbH');
        $this->db->exec("UPDATE companies SET address = 'Firmenweg 1', vat_id = 'DE987654321' WHERE id = ?", [$c]);
        $this->addMember($u['id'], $c);
        $this->db->exec("INSERT INTO customers (company_id, name, address, country, is_company, vat_id, vies_status, default_currency) VALUES (?, 'Kowalski', 'ul. Długa 12', 'PL', 1, 'PL1234567890', 'valid', 'PLN')", [$c]);
        $pl = $this->db->lastId();
        $this->db->exec("INSERT INTO customers (company_id, name, address, country, is_company, vat_id) VALUES (?, 'Nowak', 'ul. Krótka 1', 'PL', 1, 'PL999')", [$c]);
        $unverified = $this->db->lastId();
        $this->loginFully($u['secret'], 'off@example.com');
        $line = ['name' => 'Beratung', 'description' => '', 'qty' => '2', 'unit' => 'hour', 'price' => '450,00', 'discount' => '', 'vat_bp' => '1900', 'item_id' => ''];
        $form = fn (int $customer, array $over = []): array => $over + ['customer_id' => (string) $customer, 'lang' => 'de', 'currency' => 'EUR',
            'eur_rate' => '', 'issue_date' => '', 'valid_until' => '', 'intro_text' => '', 'outro_text' => '', 'notes' => '', 'lines' => [$line]];

        $pages = [];
        $pages['offer_list_empty'] = $this->get('/offers');
        $pages['offer_new'] = $this->get('/offers/new');
        $pages['offer_new_rc'] = $this->app->handle(new \App\Core\Request('GET', '/offers/new', query: ['customer_id' => (string) $pl], server: ['REMOTE_ADDR' => self::IP]));
        $pages['offer_422'] = $this->post('/offers', $form($pl, ['currency' => 'PLN', 'lines' => [['price' => 'x'] + $line]]));
        $pages['offer_add_line'] = $this->post('/offers', $form($pl) + ['add_line' => '1']);
        $created = $this->post('/offers', $form($unverified));
        $id = (int) substr($created->headers['Location'] ?? '', strlen('/offers/'));
        $pages['offer_draft_vies'] = $this->get("/offers/$id");
        $this->post("/offers/$id/issue", $form($unverified));
        $pages['offer_issued'] = $this->get("/offers/$id");
        $pages['offer_status_flash'] = $this->post("/offers/$id/status", ['status' => 'accepted']);
        $pages['offer_accepted'] = $this->get("/offers/$id");
        $invoice = $this->post("/offers/$id/convert");
        $pages['offer_converted'] = $this->get("/offers/$id");
        $pages['invoice_from_offer'] = $this->get($invoice->headers['Location'] ?? '/invoices');
        $pages['offer_list'] = $this->get('/offers');
        $pages['offer_send'] = $this->get("/documents/$id/send");

        foreach ($pages as $name => $r) {
            if ($r->status === 303) {
                continue;
            }
            $this->assertStringContainsString('<html', $r->body, $name);
            $this->assertSame(self::CSP, $r->headers['Content-Security-Policy'] ?? null, $name);
            $this->assertNoInlineMarkup($name, $r->body);
        }
        foreach (['offer_issued', 'offer_accepted', 'offer_converted', 'invoice_from_offer', 'offer_list', 'offer_send', 'offer_draft_vies'] as $name) {
            $this->assertSame(200, $pages[$name]->status, $name);
        }
        $this->assertStringContainsString('src="/assets/invoice.js', $pages['offer_draft_vies']->body);
        $json = $this->post('/offers/recalc', $form($pl));
        $this->assertArrayNotHasKey('Content-Security-Policy', $json->headers);
    }

    public function test_dunning_pages_have_no_inline_markup(): void
    {
        $u = $this->createUser('dun@example.com');
        $c = $this->createCompany('Dun GmbH');
        $this->db->exec("UPDATE companies SET address = 'Firmenweg 1', vat_id = 'DE987654321', iban = 'DE89370400440532013000', access_status = 'active' WHERE id = ?", [$c]);
        $this->addMember($u['id'], $c);
        $this->db->exec("INSERT INTO customers (company_id, name, address, country, is_company, payment_days) VALUES (?, 'Nordlicht', 'Hafen 3', 'DE', 1, 14)", [$c]);
        $customer = $this->db->lastId();
        $this->now = (new \DateTimeImmutable('2026-11-18 10:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp();
        $ctx = \App\Tenant\CompanyContext::forUser($this->db, $u['id'], $c);
        $clock = fn (): int => $this->now;
        $svc = new \App\Domain\DocumentService($this->db, $ctx, new \App\Domain\NumberSequences($this->db, $ctx, $clock), new \App\Repo\DocumentRepo($this->db, $ctx),
            new \App\Repo\CustomerRepo($this->db, $ctx), $clock);
        $invoice = $svc->createDraft('invoice', ['customer_id' => $customer, 'issue_date' => '2026-10-14', 'due_date' => '2026-10-28',
            'lines' => [['name' => 'x', 'unit' => 'flat', 'qty_milli' => 1000, 'unit_minor' => 120000, 'discount_bp' => 0, 'vat_bp' => 1900]]]);
        $svc->issue($invoice, new \DateTimeImmutable('@' . $this->now));
        $this->loginFully($u['secret'], 'dun@example.com');
        $q = fn (array $query): \App\Core\Response => $this->app->handle(new \App\Core\Request('GET', '/dunning/new', query: $query, server: ['REMOTE_ADDR' => self::IP]));

        $pages = [];
        $pages['dunning_list'] = $this->get('/dunning');
        $pages['dunning_preview'] = $q(['invoice' => (string) $invoice]);
        $pages['dunning_preview_flat'] = $q(['invoice' => (string) $invoice, 'flat_fee' => '1']);
        $pages['dunning_preview_no_base_rate'] = $q(['invoice' => (string) $invoice, 'interest' => '1']); // 422: no base rate in this company
        $pages['dunning_422'] = $this->post('/dunning', ['invoice' => (string) $invoice, 'level' => 'x']);
        $created = $this->post('/dunning', ['invoice' => (string) $invoice, 'level' => '1', 'flat_fee' => '1']);
        $id = (int) substr($created->headers['Location'] ?? '', strlen('/dunning/'));
        $pages['dunning_view'] = $this->get("/dunning/$id");
        $pages['dunning_list_issued'] = $this->get('/dunning');
        $pages['dunning_send'] = $this->get("/documents/$id/send");
        $pages['dunning_404'] = $this->get('/dunning/999999');
        foreach ($pages as $name => $r) {
            $this->assertStringContainsString('<html', $r->body, $name);
            $this->assertSame(self::CSP, $r->headers['Content-Security-Policy'] ?? null, $name);
            $this->assertNoInlineMarkup($name, $r->body);
        }
        foreach (['dunning_list', 'dunning_preview', 'dunning_preview_flat', 'dunning_view', 'dunning_list_issued', 'dunning_send'] as $name) {
            $this->assertSame(200, $pages[$name]->status, $name);
        }
        $this->assertSame(422, $pages['dunning_preview_no_base_rate']->status);
        $this->assertStringContainsString('src="/assets/dunning.js', $pages['dunning_preview']->body);
    }

    private function assertNoInlineMarkup(string $name, string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, "$name: style attribute");
        $this->assertDoesNotMatchRegularExpression('/<style[\s>]/i', $html, "$name: <style> element");
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, "$name: inline event handler");
        $this->assertDoesNotMatchRegularExpression('/href\s*=\s*["\']?\s*javascript:/i', $html, "$name: javascript: URL");
        preg_match_all('/<script\b([^>]*)>/i', $html, $m);
        foreach ($m[1] as $attrs) {
            $this->assertMatchesRegularExpression('/\ssrc="\/[^"]+"/', $attrs, "$name: inline <script>");
        }
    }
}
