<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Http\Middleware\RequireCompany;
use FastRoute\RouteCollector;

final class CompanyResolutionTest extends HttpTestCase
{
    private function addRoute(): void
    {
        $this->app->routes(function (App $app, RouteCollector $r): void {
            $r->addRoute('GET', '/_t/company', $app->protect(
                fn (Request $req, array $p): Response => Response::html('company=' . $app->context()?->id()),
                [RequireCompany::class]
            ));
        });
    }

    private function company(string $name = 'C'): int
    {
        $this->db->exec('INSERT INTO companies (name) VALUES (?)', [$name]);
        return $this->db->lastId();
    }

    private function member(int $userId, int $companyId): void
    {
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$companyId, $userId, 'owner']);
    }

    private function login(): array
    {
        $u = $this->createUser();
        $this->loginFully($u['secret']);
        return $u;
    }

    public function test_context_is_null_when_not_logged_in(): void
    {
        $this->addRoute();
        $this->get('/login');
        $this->assertNull($this->app->context());
    }

    public function test_single_company_is_auto_selected_and_stored(): void
    {
        $this->addRoute();
        $u = $this->login();
        $c = $this->company();
        $this->member($u['id'], $c);
        $r = $this->get('/_t/company');
        $this->assertSame(200, $r->status);
        $this->assertSame("company=$c", $r->body);
        $this->assertSame($c, $this->app->session()->companyId());
    }

    public function test_user_without_company_gets_403(): void
    {
        $this->addRoute();
        $this->login();
        $r = $this->get('/_t/company');
        $this->assertSame(403, $r->status);
        $this->assertStringContainsString($this->app->translator()->t('company.none_title', 'de'), $r->body);
    }

    public function test_403_page_is_translated(): void
    {
        $this->addRoute();
        $this->login();
        $this->app->session()->set('lang', 'pl');
        $r = $this->get('/_t/company');
        $this->assertSame(403, $r->status);
        $this->assertStringContainsString($this->app->translator()->t('company.none_title', 'pl'), $r->body);
    }

    public function test_several_companies_without_selection_redirect_to_chooser(): void
    {
        $this->addRoute();
        $u = $this->login();
        $this->member($u['id'], $this->company('A'));
        $this->member($u['id'], $this->company('B'));
        $this->assertRedirect('/company/choose', $this->get('/_t/company'));
        $this->assertNull($this->app->context());
    }

    public function test_selected_company_in_session_is_used_when_member(): void
    {
        $this->addRoute();
        $u = $this->login();
        $a = $this->company('A');
        $b = $this->company('B');
        $this->member($u['id'], $a);
        $this->member($u['id'], $b);
        $this->app->session()->setCompanyId($b);
        $this->assertSame("company=$b", $this->get('/_t/company')->body);
    }

    public function test_session_company_user_is_not_member_of_is_not_trusted(): void
    {
        $this->addRoute();
        $u = $this->login();
        $a = $this->company('A');
        $foreign = $this->company('Foreign');
        $this->member($u['id'], $a);
        $this->app->session()->setCompanyId($foreign);
        // single remaining company is selected instead, never the foreign one
        $this->assertSame("company=$a", $this->get('/_t/company')->body);
        $this->assertSame($a, $this->app->session()->companyId());
    }

    public function test_removed_membership_with_several_companies_falls_back_to_chooser(): void
    {
        $this->addRoute();
        $u = $this->login();
        $a = $this->company('A');
        $b = $this->company('B');
        $c = $this->company('C');
        $this->member($u['id'], $a);
        $this->member($u['id'], $b);
        $this->member($u['id'], $c);
        $this->app->session()->setCompanyId($c);
        $this->db->exec('DELETE FROM company_users WHERE company_id = ? AND user_id = ?', [$c, $u['id']]);
        $this->assertRedirect('/company/choose', $this->get('/_t/company'));
        $this->assertNull($this->app->session()->companyId());
    }

    public function test_company_id_from_request_is_ignored(): void
    {
        $this->addRoute();
        $u = $this->login();
        $a = $this->company('A');
        $foreign = $this->company('Foreign');
        $this->member($u['id'], $a);
        $r = $this->app->handle(new Request('GET', '/_t/company', query: ['company_id' => (string) $foreign], server: ['REMOTE_ADDR' => self::IP]));
        $this->assertSame("company=$a", $r->body);
    }

    public function test_context_is_cached_per_request(): void
    {
        $this->addRoute();
        $u = $this->login();
        $a = $this->company('A');
        $this->member($u['id'], $a);
        $this->get('/_t/company');
        $first = $this->app->context();
        $this->assertSame($first, $this->app->context());
        // new request builds a new context
        $this->get('/_t/company');
        $this->assertNotSame($first, $this->app->context());
    }

    public function test_logout_clears_company_selection(): void
    {
        $this->addRoute();
        $u = $this->login();
        $this->member($u['id'], $this->company('A'));
        $this->get('/_t/company');
        $this->assertNotNull($this->app->session()->companyId());
        $this->post('/logout');
        $this->assertNull($this->app->session()->companyId());
        $this->assertNull($this->app->context());
    }

    public function test_session_company_id_cleared_on_stage_change(): void
    {
        $this->app->handle(new Request('GET', '/login', server: ['REMOTE_ADDR' => self::IP]));
        $s = $this->app->session();
        $s->passwordOk(1);
        $s->setCompanyId(5);
        $s->fullLogin();
        $this->assertNull($s->companyId());
        $s->setCompanyId(5);
        $s->passwordOk(2);
        $this->assertNull($s->companyId());
    }

    public function test_password_ok_stage_has_no_context(): void
    {
        $this->addRoute();
        $u = $this->createUser();
        $c = $this->company();
        $this->member($u['id'], $c);
        $this->loginPassword();
        $this->assertNull($this->app->context());
    }

    public function test_orphaned_membership_only_gives_403(): void
    {
        $this->addRoute();
        $u = $this->login();
        $c = $this->company();
        $this->member($u['id'], $c);
        $this->db->exec('DELETE FROM companies WHERE id = ?', [$c]);
        $this->assertSame(403, $this->get('/_t/company')->status);
    }

    public function test_valid_plus_orphaned_membership_selects_valid(): void
    {
        $this->addRoute();
        $u = $this->login();
        $valid = $this->company('V');
        $gone = $this->company('G');
        $this->member($u['id'], $valid);
        $this->member($u['id'], $gone);
        $this->db->exec('DELETE FROM companies WHERE id = ?', [$gone]);
        $this->assertSame("company=$valid", $this->get('/_t/company')->body);
    }

    public function test_reset_context_forgets_cached_context(): void
    {
        $this->addRoute();
        $u = $this->login();
        $a = $this->company('A');
        $this->member($u['id'], $a);
        $this->get('/_t/company');
        $first = $this->app->context();
        $this->app->resetContext();
        $this->assertNotSame($first, $this->app->context());
    }
}
