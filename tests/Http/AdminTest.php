<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Http\Middleware\RequireCompany;
use App\Tenant\CompanyContext;
use FastRoute\RouteCollector;

final class AdminTest extends HttpTestCase
{
    private const ROUTES = [
        ['GET', '/admin/companies'],
        ['GET', '/admin/companies/new'],
        ['POST', '/admin/companies'],
        ['GET', '/admin/companies/1'],
        ['POST', '/admin/companies/1/access'],
        ['POST', '/admin/companies/1/users'],
        ['POST', '/admin/companies/1/users/1/remove'],
    ];

    private function admin(string $email = 'admin@example.com'): array
    {
        $u = $this->createUser($email);
        $this->db->exec('UPDATE users SET is_admin = 1 WHERE id = ?', [$u['id']]);
        $this->loginFully($u['secret'], $email);
        return $u;
    }

    private function company(string $name = 'Acme', string $status = 'suspended', ?string $until = null): int
    {
        $this->db->exec('INSERT INTO companies (name, access_status, access_until) VALUES (?, ?, ?)', [$name, $status, $until]);
        return $this->db->lastId();
    }

    private function audit(): array
    {
        return $this->db->all('SELECT * FROM audit_log ORDER BY id');
    }

    public function test_non_admin_gets_403(): void
    {
        $u = $this->createUser();
        $this->loginFully($u['secret']);
        $c = $this->company();
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$c, $u['id'], 'owner']);
        foreach (self::ROUTES as [$method, $path]) {
            $r = $method === 'GET'
                ? $this->get($path)
                : $this->post($path, ['name' => 'Hack', 'action' => 'activate', 'email' => 'jan@example.com', 'role' => 'user']);
            $this->assertSame(403, $r->status, "$method $path");
            $this->assertStringNotContainsString('Acme', $r->body);
        }
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM companies')['c']);
        $this->assertSame('suspended', $this->db->one('SELECT access_status s FROM companies WHERE id = ?', [$c])['s']);
        $this->assertSame([], $this->audit());
    }

    public function test_anonymous_is_redirected_to_login(): void
    {
        foreach (self::ROUTES as [$method, $path]) {
            $r = $method === 'GET' ? $this->get($path) : $this->post($path);
            $this->assertRedirect('/login', $r);
        }
    }

    public function test_admin_without_company_can_use_admin_pages(): void
    {
        $this->admin();
        $r = $this->get('/admin/companies');
        $this->assertSame(200, $r->status);
        $this->assertSame(200, $this->get('/admin/companies/new')->status);
    }

    public function test_admin_can_create_company_and_add_user(): void
    {
        $this->admin();
        $r = $this->post('/admin/companies', ['name' => 'Muster GmbH', 'plan' => 'standard', 'access_status' => 'suspended']);
        $this->assertSame(302, $r->status);
        $c = $this->db->one('SELECT * FROM companies WHERE name = ?', ['Muster GmbH']);
        $this->assertNotNull($c);
        $this->assertSame('/admin/companies/' . $c['id'], $r->headers['Location']);
        $this->assertSame('suspended', $c['access_status']);

        $r = $this->post("/admin/companies/{$c['id']}/users", ['email' => 'Neu@Example.com', 'role' => 'owner', 'password' => 'correct horse battery']);
        $this->assertSame(302, $r->status);
        $page = $this->get("/admin/companies/{$c['id']}");
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('neu@example.com', $page->body);
        $this->assertStringNotContainsString('correct horse battery', $page->body);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM company_users WHERE company_id = ?', [$c['id']])['c']);
        $this->assertStringContainsString('Muster GmbH', $this->get('/admin/companies')->body);
    }

    public function test_unknown_company_is_404(): void
    {
        $this->admin();
        $this->assertSame(404, $this->get('/admin/companies/999')->status);
        $this->assertSame(404, $this->post('/admin/companies/999/access', ['action' => 'suspend'])->status);
        $this->assertSame(404, $this->post('/admin/companies/999/users', ['email' => 'a@example.com', 'role' => 'user'])->status);
        $this->assertSame(404, $this->post('/admin/companies/999/users/1/remove')->status);
        $c = $this->company();
        $this->assertSame(404, $this->post("/admin/companies/$c/users/999/remove")->status);
    }

    public function test_activate_and_suspend_with_audit(): void
    {
        $a = $this->admin();
        $c = $this->company();
        $r = $this->post("/admin/companies/$c/access", ['action' => 'activate', 'until' => '2027-06-30']);
        $this->assertRedirect('/admin/companies', $r);
        $row = $this->db->one('SELECT * FROM companies WHERE id = ?', [$c]);
        $this->assertSame('active', $row['access_status']);
        $this->assertSame('2027-06-30', substr((string) $row['access_until'], 0, 10));

        $this->assertRedirect('/admin/companies', $this->post("/admin/companies/$c/access", ['action' => 'suspend']));
        $this->assertSame('suspended', $this->db->one('SELECT access_status s FROM companies WHERE id = ?', [$c])['s']);

        $log = $this->audit();
        $this->assertCount(2, $log);
        $this->assertSame('admin.access_change', $log[0]['action']);
        $this->assertSame($c, (int) $log[0]['company_id']);
        $this->assertSame($a['id'], (int) $log[0]['user_id']);
        $d = json_decode($log[0]['detail'], true);
        $this->assertSame(['company_id' => $c, 'old_status' => 'suspended', 'new_status' => 'active', 'old_until' => null, 'new_until' => '2027-06-30'], $d);
        $d = json_decode($log[1]['detail'], true);
        $this->assertSame('active', $d['old_status']);
        $this->assertSame('suspended', $d['new_status']);
        $this->assertNull($d['new_until']);
    }

    public function test_activate_default_until_is_one_year_from_today_in_berlin(): void
    {
        // 2023-12-31 23:30 UTC is already 2024-01-01 in Berlin (set before login: sessions are clock-bound).
        $this->now = gmmktime(23, 30, 0, 12, 31, 2023);
        $this->admin();
        $c = $this->company();
        $this->post("/admin/companies/$c/access", ['action' => 'activate', 'until' => '']);
        $this->assertSame('2025-01-01', substr((string) $this->db->one('SELECT access_until u FROM companies WHERE id = ?', [$c])['u'], 0, 10));
    }

    public function test_invalid_status_or_until_rejected_without_change(): void
    {
        $this->admin();
        $c = $this->company('Acme', 'suspended', null);
        foreach ([['action' => 'activate', 'until' => 'soon'], ['action' => 'activate', 'until' => '2027-02-30'], ['action' => 'delete'], []] as $data) {
            $r = $this->post("/admin/companies/$c/access", $data);
            $this->assertSame(422, $r->status);
            $this->assertStringContainsString('Acme', $r->body);
        }
        $row = $this->db->one('SELECT * FROM companies WHERE id = ?', [$c]);
        $this->assertSame('suspended', $row['access_status']);
        $this->assertNull($row['access_until']);
        $this->assertSame([], $this->audit());
    }

    public function test_create_validation_errors_are_inline_and_nothing_stored(): void
    {
        $this->admin();
        $r = $this->post('/admin/companies', ['name' => '', 'plan' => 'free', 'access_status' => 'suspended']);
        $this->assertSame(422, $r->status);
        $r = $this->post('/admin/companies', ['name' => 'T', 'plan' => 'free', 'access_status' => 'trial', 'access_until' => '']);
        $this->assertSame(422, $r->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM companies')['c']);
        $this->assertSame([], $this->audit());
    }

    public function test_duplicate_membership_and_bad_input_rejected(): void
    {
        $this->admin();
        $c = $this->company();
        $this->post("/admin/companies/$c/users", ['email' => 'a@example.com', 'role' => 'user', 'password' => 'correct horse battery']);
        $before = count($this->audit());
        foreach ([
            ['email' => 'a@example.com', 'role' => 'user'],
            ['email' => 'nobody@example.com', 'role' => 'user'],
            ['email' => 'b@example.com', 'role' => 'admin', 'password' => 'correct horse battery'],
            ['email' => 'c@example.com', 'role' => 'user', 'password' => 'short'],
        ] as $data) {
            $r = $this->post("/admin/companies/$c/users", $data);
            $this->assertSame(422, $r->status);
            $this->assertStringNotContainsString('correct horse battery', $r->body);
        }
        $this->assertCount($before, $this->audit());
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM company_users')['c']);
    }

    public function test_remove_user_with_audit(): void
    {
        $this->admin();
        $c = $this->company();
        $this->post("/admin/companies/$c/users", ['email' => 'a@example.com', 'role' => 'user', 'password' => 'correct horse battery']);
        $uid = (int) $this->db->one('SELECT id FROM users WHERE email = ?', ['a@example.com'])['id'];
        $this->assertSame(302, $this->post("/admin/companies/$c/users/$uid/remove")->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM company_users')['c']);
        $last = array_values(array_filter($this->audit(), fn ($r) => $r['action'] === 'admin.user_remove'));
        $this->assertCount(1, $last);
        $this->assertSame(['company_id' => $c, 'user_id' => $uid], json_decode($last[0]['detail'], true));
    }

    public function test_audit_rows_for_create_and_add_never_contain_password(): void
    {
        $this->admin();
        $this->post('/admin/companies', ['name' => 'Muster', 'plan' => 'free', 'access_status' => 'trial', 'access_until' => '2027-02-01']);
        $c = (int) $this->db->one('SELECT id FROM companies')['id'];
        $this->post("/admin/companies/$c/users", ['email' => 'n@example.com', 'role' => 'owner', 'password' => 'correct horse battery']);
        $log = $this->audit();
        $this->assertSame(['admin.company_create', 'admin.user_add'], array_column($log, 'action'));
        $this->assertSame(
            ['company_id' => $c, 'name' => 'Muster', 'plan' => 'free', 'status' => 'trial', 'until' => '2027-02-01'],
            json_decode($log[0]['detail'], true)
        );
        $d = json_decode($log[1]['detail'], true);
        $this->assertSame($c, $d['company_id']);
        $this->assertSame('n@example.com', $d['email']);
        $this->assertSame('owner', $d['role']);
        foreach ($log as $row) {
            $this->assertStringNotContainsString('correct horse battery', json_encode($row));
            $this->assertStringNotContainsString('password', strtolower((string) $row['detail']));
        }
    }

    public function test_company_name_is_escaped(): void
    {
        $this->admin();
        $c = $this->company('<script>alert(1)</script>');
        foreach (['/admin/companies', "/admin/companies/$c"] as $path) {
            $body = $this->get($path)->body;
            $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        }
    }

    public function test_post_without_csrf_is_419(): void
    {
        $this->admin();
        $c = $this->company();
        foreach (['/admin/companies', "/admin/companies/$c/access", "/admin/companies/$c/users", "/admin/companies/$c/users/1/remove"] as $path) {
            $this->assertSame(419, $this->post($path, ['name' => 'X', 'action' => 'activate'], csrf: false)->status);
        }
        $this->assertSame('suspended', $this->db->one('SELECT access_status s FROM companies WHERE id = ?', [$c])['s']);
    }

    public function test_pages_render_texts_and_buttons_per_status(): void
    {
        $this->admin();
        $this->company('Susp', 'suspended');
        $this->company('Live', 'active', '2027-01-01');
        $body = $this->get('/admin/companies')->body;
        $this->assertStringContainsString('Verwaltung', $body);
        $this->assertStringContainsString('Aktivieren', $body);
        $this->assertStringContainsString('Sperren', $body);
        $this->assertStringContainsString('Gesperrte Firmen können sich anmelden', $body);
        $this->assertStringContainsString('2027-01-01', $body);
    }

    // --- access semantics -------------------------------------------------

    private function ctx(int $userId, int $companyId): CompanyContext
    {
        return CompanyContext::forUser($this->db, $userId, $companyId);
    }

    private function member(int $uid, int $cid): void
    {
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$cid, $uid, 'owner']);
    }

    private function clock(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->now));
    }

    public function test_suspended_company_cannot_issue(): void
    {
        $u = $this->createUser('m@example.com', false);
        $c = $this->company('S', 'suspended');
        $this->member($u['id'], $c);
        $this->assertFalse($this->ctx($u['id'], $c)->canIssue($this->clock()));
    }

    public function test_expired_trial_cannot_issue(): void
    {
        $u = $this->createUser('m@example.com', false);
        $c = $this->company('T', 'trial', gmdate('Y-m-d', $this->now - 3 * 86400));
        $this->member($u['id'], $c);
        $this->assertFalse($this->ctx($u['id'], $c)->canIssue($this->clock()));
    }

    public function test_suspended_company_user_can_still_log_in_and_read(): void
    {
        $this->app->routes(function (App $app, RouteCollector $r): void {
            $r->addRoute('GET', '/_t/read', $app->protect(
                fn (Request $req, array $p): Response => Response::html('read ' . $app->context()?->company()['name']),
                [RequireCompany::class]
            ));
        });
        $u = $this->createUser('m@example.com');
        $c = $this->company('Frozen', 'suspended');
        $this->member($u['id'], $c);
        $this->loginFully($u['secret'], 'm@example.com');
        $r = $this->get('/_t/read');
        $this->assertSame(200, $r->status);
        $this->assertSame('read Frozen', $r->body);
        $this->assertFalse($this->app->context()->canIssue($this->clock()));
    }

    public function test_activating_a_company_makes_canissue_true_on_next_request(): void
    {
        $this->app->routes(function (App $app, RouteCollector $r): void {
            $r->addRoute('GET', '/_t/can', $app->protect(
                fn (Request $req, array $p): Response => Response::html($app->context()->canIssue(new \DateTimeImmutable('@' . $app->now())) ? 'yes' : 'no'),
                [RequireCompany::class]
            ));
        });
        $m = $this->createUser('m@example.com');
        $c = $this->company('Pay', 'suspended');
        $this->member($m['id'], $c);
        $this->loginFully($m['secret'], 'm@example.com');
        $this->assertSame('no', $this->get('/_t/can')->body);

        // Admin activates (separate DB write path via repo, as the admin request would do).
        $this->now += 31;
        $adminUser = $this->createUser('admin@example.com');
        $this->db->exec('UPDATE users SET is_admin = 1 WHERE id = ?', [$adminUser['id']]);
        $this->post('/logout');
        $this->loginFully($adminUser['secret'], 'admin@example.com');
        $this->assertRedirect('/admin/companies', $this->post("/admin/companies/$c/access", ['action' => 'activate', 'until' => '']));
        $this->post('/logout');
        $this->now += 31;
        $this->loginFully($m['secret'], 'm@example.com');
        $this->assertSame('yes', $this->get('/_t/can')->body);
    }
}
