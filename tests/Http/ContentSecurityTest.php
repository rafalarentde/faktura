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
