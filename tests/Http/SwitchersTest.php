<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Http\BackPath;

/** POST /lang, the redirect-back parameter, GET /company/choose and POST /company/switch. */
final class SwitchersTest extends HttpTestCase
{
    private function login(): array
    {
        $u = $this->createUser();
        $this->loginFully($u['secret']);
        return $u;
    }

    private function uiLang(int $id): string
    {
        return (string) $this->db->one('SELECT ui_lang FROM users WHERE id = ?', [$id])['ui_lang'];
    }

    public function test_lang_switch_updates_session_and_user(): void
    {
        $u = $this->login();
        $this->addMember($u['id'], $this->createCompany());
        $this->assertRedirect('/', $this->post('/lang', ['lang' => 'pl', 'back' => '/']));
        $this->assertSame('pl', $this->app->session()->get('lang'));
        $this->assertSame('pl', $this->uiLang($u['id']));
        $this->assertStringContainsString('<html lang="pl"', $this->get('/')->body);
    }

    public function test_lang_switch_rejects_unsupported_value(): void
    {
        $u = $this->login();
        foreach (['fr', '', 'DE', 'de ', 'pl;'] as $bad) {
            $r = $this->post('/lang', ['lang' => $bad, 'back' => '/']);
            $this->assertSame(422, $r->status, var_export($bad, true));
        }
        $this->assertNotSame('fr', $this->app->session()->get('lang'));
        $this->assertSame('de', $this->uiLang($u['id']));
    }

    public function test_lang_switch_requires_csrf(): void
    {
        $u = $this->login();
        $this->assertSame(419, $this->post('/lang', ['lang' => 'pl'], csrf: false)->status);
        $this->assertSame('de', $this->uiLang($u['id']));
    }

    public function test_lang_switch_works_before_login_without_touching_users(): void
    {
        $u = $this->createUser();
        $this->get('/login');
        $this->assertRedirect('/login', $this->post('/lang', ['lang' => 'en', 'back' => '/login']));
        $this->assertSame('en', $this->app->session()->get('lang'));
        $this->assertSame('de', $this->uiLang($u['id']));
        $this->assertStringContainsString('<html lang="en"', $this->get('/login')->body);
    }

    public function test_back_parameter_rejects_external_targets(): void
    {
        $this->login();
        $bad = ['//evil.com', 'https://evil.com', '/\\evil', 'javascript:alert(1)', 'evil.com', "/a\r\nX: y", '\\\\evil', '///evil.com', ''];
        foreach ($bad as $back) {
            $this->assertRedirect('/', $this->post('/lang', ['lang' => 'de', 'back' => $back]));
        }
        $this->assertRedirect('/admin/companies?x=1', $this->post('/lang', ['lang' => 'de', 'back' => '/admin/companies?x=1']));
    }

    public function test_back_path_unit(): void
    {
        foreach (['//evil.com', 'https://evil.com', '/\\evil', 'javascript:', 'JavaScript:alert(1)', "/x\0", "/x\ty", '/a//b', ' /x'] as $bad) {
            $this->assertSame('/', BackPath::sanitize($bad), var_export($bad, true));
        }
        $this->assertSame('/', BackPath::sanitize(null));
        $this->assertSame('/', BackPath::sanitize(str_repeat('/a', 1100)));
        $this->assertSame('/customers/5', BackPath::sanitize('/customers/5'));
        $this->assertSame('/', BackPath::sanitize('/'));
    }

    public function test_chooser_lists_only_valid_memberships(): void
    {
        $u = $this->login();
        $a = $this->createCompany('Alpha GmbH');
        $b = $this->createCompany('Beta Sp. z o.o.');
        $this->createCompany('Fremd AG'); // not a member
        $this->addMember($u['id'], $a);
        $this->addMember($u['id'], $b);
        $this->addMember($u['id'], 999); // orphan membership: the company does not exist
        $r = $this->get('/company/choose');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Alpha GmbH', $r->body);
        $this->assertStringContainsString('Beta Sp. z o.o.', $r->body);
        $this->assertStringNotContainsString('Fremd AG', $r->body);
        $this->assertStringNotContainsString('value="999"', $r->body);
        $this->assertSame(2, substr_count($r->body, 'action="/company/switch"'));
    }

    public function test_chooser_requires_login(): void
    {
        $this->assertRedirect('/login', $this->get('/company/choose'));
    }

    public function test_switch_to_foreign_company_is_403_and_selection_unchanged(): void
    {
        $u = $this->login();
        $a = $this->createCompany('Alpha');
        $b = $this->createCompany('Beta');
        $foreign = $this->createCompany('Fremd');
        $this->addMember($u['id'], $a);
        $this->addMember($u['id'], $b);
        $this->app->session()->setCompanyId($a);
        $r = $this->post('/company/switch', ['company_id' => (string) $foreign]);
        $this->assertSame(403, $r->status);
        $this->assertStringNotContainsString('Fremd', $r->body);
        $this->assertSame($a, $this->app->session()->companyId());
        foreach (['', 'abc', '-1', '999'] as $bad) {
            $this->assertSame(403, $this->post('/company/switch', ['company_id' => $bad])->status, $bad);
        }
        $this->assertSame($a, $this->app->session()->companyId());
    }

    public function test_switch_to_own_company_persists_across_requests(): void
    {
        $u = $this->login();
        $a = $this->createCompany('Alpha');
        $b = $this->createCompany('Beta');
        $this->addMember($u['id'], $a);
        $this->addMember($u['id'], $b);
        $this->assertRedirect('/company/choose', $this->get('/'));
        $this->assertRedirect('/', $this->post('/company/switch', ['company_id' => (string) $b]));
        $this->assertSame($b, $this->app->session()->companyId());
        $r = $this->get('/');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Beta', $r->body);
        $this->assertSame($b, $this->app->context()?->id());
        // Switching back also works, and an unsafe back target falls back to /.
        $this->assertRedirect('/', $this->post('/company/switch', ['company_id' => (string) $a, 'back' => '//evil.com']));
        $this->get('/');
        $this->assertSame($a, $this->app->context()?->id());
    }

    public function test_switch_requires_csrf(): void
    {
        $u = $this->login();
        $a = $this->createCompany('Alpha');
        $this->addMember($u['id'], $a);
        $this->assertSame(419, $this->post('/company/switch', ['company_id' => (string) $a], csrf: false)->status);
    }
}
