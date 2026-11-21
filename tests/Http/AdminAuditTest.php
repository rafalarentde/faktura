<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\Response;

/** GET /admin/audit (Task 23): admin-only, read-only, filters, pagination, escaping. */
final class AdminAuditTest extends HttpTestCase
{
    private int $adminId;

    private function admin(): void
    {
        $u = $this->createUser('admin@example.com');
        $this->adminId = $u['id'];
        $this->db->exec('UPDATE users SET is_admin = 1 WHERE id = ?', [$u['id']]);
        $this->loginFully((string) $u['secret'], 'admin@example.com');
    }

    private function row(string $action, ?int $company = null, ?int $user = null, string $detail = '{}', string $at = '2026-11-18 09:00:00'): int
    {
        $this->db->exec('INSERT INTO audit_log (company_id, user_id, action, detail, at) VALUES (?, ?, ?, ?, ?)', [$company, $user, $action, $detail, $at]);
        return $this->db->lastId();
    }

    /** @param array<string, string> $query */
    private function page(array $query = []): Response
    {
        return $this->app->handle(new \App\Core\Request('GET', '/admin/audit', query: $query, server: ['REMOTE_ADDR' => self::IP]));
    }

    /** @return list<string> actions listed in the table body, in order */
    private function actions(Response $r): array
    {
        preg_match_all('#<td><code>([^<]*)</code></td>#', $r->body, $m);
        return array_map(fn (string $a): string => html_entity_decode($a), $m[1]);
    }

    public function test_only_admins_see_the_log(): void
    {
        $this->assertRedirect('/login', $this->get('/admin/audit'));
        $u = $this->createUser('normal@example.com');
        $this->loginFully((string) $u['secret'], 'normal@example.com');
        $r = $this->get('/admin/audit');
        $this->assertSame(403, $r->status);
        $this->assertStringNotContainsString('<table', $r->body);
        $c = $this->createCompany('Acme');
        $this->addMember($u['id'], $c, 'owner');
        $this->assertSame(403, $this->get('/admin/audit')->status, 'a company owner is no admin');
    }

    public function test_the_route_is_in_the_admin_route_table_and_read_only(): void
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
        $this->assertSame([['GET', '/admin/audit']], array_values(array_filter($collector->seen, fn (array $r): bool => str_starts_with($r[1], '/admin/audit'))));
        $this->admin();
        $this->assertSame(405, $this->post('/admin/audit')->status);
        $this->assertSame(405, $this->app->handle(new \App\Core\Request('DELETE', '/admin/audit'))->status);
    }

    public function test_lists_newest_first_with_berlin_time_names_and_escaped_details(): void
    {
        $this->admin();
        $c = $this->createCompany('Muster <b>GmbH</b>');
        $this->db->exec("INSERT INTO users (email, password_hash) VALUES ('jan+<x>@example.pl', 'x')");
        $u = ['id' => $this->db->lastId()];
        $this->row('settings.company_save', $c, $u['id'], '{"fields":["name","iban"]}', '2026-07-01 10:30:00');
        $this->row('auth.login_failed', null, null, '{"reason":"bad_password","key":"abcdef012345"}', '2026-11-18 09:00:00');
        $this->row('x.flags', $c, null, '{"ok":true,"n":5,"none":null,"s":"<script>alert(1)</script>"}', '2026-11-18 09:01:00');
        $this->row('x.broken', null, null, 'not json <i>', '2026-11-18 09:02:00');
        $r = $this->page();
        $this->assertSame(200, $r->status);
        $actions = $this->actions($r);
        $this->assertSame('x.broken', $actions[0], 'newest (highest id) first');
        $this->assertSame(['x.broken', 'x.flags', 'auth.login_failed', 'settings.company_save'], array_slice($actions, 0, 4));
        $this->assertStringContainsString('2026-07-01 12:30:00', $r->body, 'summer time: UTC+2');
        $this->assertStringContainsString('2026-11-18 10:00:00', $r->body, 'winter time: UTC+1');
        $this->assertStringContainsString('<code>fields=name,iban</code>', $r->body);
        $this->assertStringContainsString('<code>reason=bad_password</code>', $r->body);
        $this->assertStringContainsString('<code>ok=true</code>', $r->body);
        $this->assertStringContainsString('<code>none=null</code>', $r->body);
        $this->assertStringContainsString('Muster &lt;b&gt;GmbH&lt;/b&gt;', $r->body);
        $this->assertStringContainsString('jan+&lt;x&gt;@example.pl', $r->body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $r->body);
        $this->assertStringNotContainsString('<script>alert', $r->body);
        $this->assertStringNotContainsString('<b>GmbH', $r->body);
        $this->assertStringContainsString('<code>not json &lt;i&gt;</code>', $r->body);
        $this->assertStringContainsString('<td class="wrap">—</td>', $r->body, 'no company/user shows a dash');
        $this->assertDoesNotMatchRegularExpression('/\sstyle=|<style|\son[a-z]+=/i', $r->body);
        // read-only: the page itself has no state-changing form
        $this->assertStringNotContainsString('action="/admin/audit" method="post"', $r->body);
        $this->assertDoesNotMatchRegularExpression('#<form[^>]*method="post"[^>]*action="/admin/audit#', $r->body);
    }

    public function test_company_and_action_filters(): void
    {
        $this->admin();
        $a = $this->createCompany('Alpha');
        $b = $this->createCompany('Beta');
        $this->row('document.issue', $a);
        $this->row('document.email_send', $a);
        $this->row('auth.login_failed', null);
        $this->row('auth.loginXfailed', null);
        $this->row('document.issue', $b);
        $this->row('Auth.Login_Other', $b);
        $this->assertSame(['document.email_send', 'document.issue'], $this->actions($this->page(['company' => (string) $a])));
        $this->assertSame(['document.issue', 'document.email_send', 'document.issue'], $this->actions($this->page(['action' => 'document.'])));
        $this->assertCount(3, $this->actions($this->page(['action' => 'document'])));
        $this->assertCount(1, $this->actions($this->page(['company' => (string) $b, 'action' => 'document.is'])));
        $this->assertCount(0, $this->actions($this->page(['company' => (string) $a, 'action' => 'auth'])));
        // the underscore is a literal, not a LIKE wildcard; prefix matching is case-insensitive
        $this->assertSame(['auth.login_failed'], $this->actions($this->page(['action' => 'auth.login_f'])));
        $this->assertCount(4, $this->actions($this->page(['action' => 'AUTH.LOGIN']))); // the admin's own auth.login plus three seeded rows
        $this->assertSame(['Auth.Login_Other'], array_values(array_filter($this->actions($this->page(['action' => 'auth.login_o'])))));
        $r = $this->page(['company' => (string) $a, 'action' => 'document.is']);
        $this->assertStringContainsString('name="company" value="' . $a . '"', $r->body);
        $this->assertStringContainsString('name="action" value="document.is"', $r->body);
        $this->assertStringContainsString('href="/admin/audit">', $r->body, 'a reset link while filtering');
        $this->assertStringContainsString('Keine Ereignisse', $this->page(['action' => 'zzz'])->body);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function hostileFilters(): iterable
    {
        foreach (['%', '_', '%_%', "'", '"', '\\', ';', '--', '/*', "' OR 1=1 --", '<script>', '?', '[a]', '{b}', "\0", "ä\u{0301}", str_repeat('a', 5000), '%%%%%%%%', "a'b\"c", '!', '!_', 'x!'] as $v) {
            yield 'action ' . bin2hex(substr($v, 0, 6)) => [['action' => $v]];
            yield 'company ' . bin2hex(substr($v, 0, 6)) => [['company' => $v]];
        }
        yield 'arrays' => [['company' => ['1'], 'action' => ['x'], 'page' => ['2']]];
        yield 'huge page' => [['page' => '99999999999999999999']];
        yield 'negative page' => [['page' => '-3']];
        yield 'zero page' => [['page' => '0']];
        yield 'company overflow' => [['company' => '99999999999999999999999']];
        yield 'company zero' => [['company' => '0']];
    }

    /** @param array<string, mixed> $query */
    #[\PHPUnit\Framework\Attributes\DataProvider('hostileFilters')]
    public function test_odd_filter_values_never_cause_an_error(array $query): void
    {
        $this->admin();
        $this->row('document.issue', null);
        $r = $this->app->handle(new \App\Core\Request('GET', '/admin/audit', query: $query, server: ['REMOTE_ADDR' => self::IP]));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('<table', $r->body);
    }

    public function test_percent_and_underscore_are_no_wildcards(): void
    {
        $this->admin();
        $this->row('document.issue');
        $this->row('settings.company_save');
        $all = $this->actions($this->page());
        $this->assertCount(4, $all, 'two rows of the admin login and the two above');
        $this->assertSame($all, $this->actions($this->page(['action' => '%'])), '% is stripped, so there is no filter');
        $this->assertSame($all, $this->actions($this->page(['action' => '%%'])));
        $this->assertSame([], $this->actions($this->page(['action' => '_'])), 'no action starts with a literal underscore');
        $this->assertSame([], $this->actions($this->page(['action' => '_ocument'])), '_ does not stand for one character');
    }

    public function test_only_the_latest_200_events_in_pages_of_50(): void
    {
        $this->admin();
        $this->db->exec("DELETE FROM audit_log");
        $first = 0;
        $this->db->exec('BEGIN');
        for ($i = 1; $i <= 260; $i++) {
            $id = $this->row('bulk.event', null, null, '{"n":' . $i . '}');
            $first = $first ?: $id;
        }
        $this->db->exec('COMMIT');
        $p1 = $this->page();
        $this->assertCount(50, $this->actions($p1));
        $this->assertStringContainsString('<code>n=260</code>', $p1->body);
        $this->assertStringContainsString('<code>n=211</code>', $p1->body);
        $this->assertStringNotContainsString('<code>n=210</code>', $p1->body);
        $this->assertStringContainsString('Seite 1 von 4', $p1->body);
        $this->assertStringContainsString('href="/admin/audit?page=2" rel="next"', $p1->body);
        $this->assertStringNotContainsString('rel="prev"', $p1->body);
        $p4 = $this->page(['page' => '4']);
        $this->assertCount(50, $this->actions($p4));
        $this->assertStringContainsString('<code>n=61</code>', $p4->body);
        $this->assertStringContainsString('<code>n=110</code>', $p4->body);
        $this->assertStringNotContainsString('<code>n=60</code>', $p4->body);
        $this->assertStringNotContainsString('rel="next"', $p4->body);
        $this->assertStringContainsString('href="/admin/audit?page=3" rel="prev"', $p4->body);
        $this->assertStringContainsString('Seite 4 von 4', $this->page(['page' => '9'])->body, 'a page past the end shows the last one');
        $this->assertStringContainsString('Seite 1 von 4', $this->page(['page' => 'abc'])->body);
    }

    public function test_pager_links_keep_the_filters(): void
    {
        $this->admin();
        $c = $this->createCompany('Alpha');
        $this->db->exec('BEGIN');
        for ($i = 1; $i <= 60; $i++) {
            $this->row('bulk.event', $c);
        }
        $this->db->exec('COMMIT');
        $r = $this->page(['company' => (string) $c, 'action' => 'bulk', 'page' => '1']);
        $this->assertStringContainsString('href="/admin/audit?company=' . $c . '&amp;action=bulk&amp;page=2"', $r->body);
        $this->assertStringContainsString('Seite 1 von 2', $r->body);
        $this->assertCount(10, $this->actions($this->page(['company' => (string) $c, 'action' => 'bulk', 'page' => '2'])));
    }

    public function test_admin_pages_link_to_each_other(): void
    {
        $this->admin();
        $companies = $this->get('/admin/companies');
        $this->assertStringContainsString('href="/admin/audit"', $companies->body);
        $this->assertStringContainsString('href="/admin/companies" aria-current="page"', $companies->body);
        $audit = $this->get('/admin/audit');
        $this->assertStringContainsString('href="/admin/companies"', $audit->body);
        $this->assertStringContainsString('href="/admin/audit" aria-current="page"', $audit->body);
    }

    public function test_the_login_of_the_admin_shows_up_without_any_email(): void
    {
        $this->admin();
        $r = $this->get('/admin/audit');
        $this->assertContains('auth.login', $this->actions($r));
        $this->assertContains('auth.2fa_ok', $this->actions($r));
        $this->assertStringContainsString('admin@example.com', $r->body, 'the user column shows the address (join), details never do');
        $this->assertDoesNotMatchRegularExpression('#<code>[a-z_]+=[^<]*@[^<]*</code>#', $r->body);
    }

    public function test_renders_in_all_languages(): void
    {
        $this->admin();
        foreach (['de' => 'Ereignisprotokoll', 'pl' => 'Dziennik zdarzeń', 'en' => 'Event log'] as $lang => $title) {
            $this->post('/lang', ['lang' => $lang]);
            $r = $this->get('/admin/audit');
            $this->assertSame(200, $r->status);
            $this->assertStringContainsString($title, $r->body, $lang);
            $this->assertDoesNotMatchRegularExpression('/>\s*audit\.[a-z_]+\s*</', $r->body, "$lang: raw key");
        }
    }
}
