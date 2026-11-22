<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HookPdo;

/** /settings/security* need a login only (no company); admin "temporary password"; hit-first current-password check. */
final class SecurityRoutesTest extends HttpTestCase
{
    private function admin(bool $withCompany = false): array
    {
        $u = $this->createUser('admin@example.com');
        $this->db->exec('UPDATE users SET is_admin = 1 WHERE id = ?', [$u['id']]);
        if ($withCompany) {
            $this->addMember($u['id'], $this->createCompany());
        }
        $this->loginFully($u['secret'], 'admin@example.com');
        return $u;
    }

    public function test_an_admin_without_a_company_can_use_the_security_page(): void
    {
        $this->admin();
        $this->assertSame(403, $this->get('/settings/company')->status, 'company settings still need a company');
        $page = $this->get('/settings/security');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('action="/settings/security/password"', $page->body);
        $this->assertStringNotContainsString('tabs-pill', $page->body, 'no company, no settings tabs');
        $r = $this->post('/settings/security/password', ['current_password' => self::PASSWORD, 'new_password' => 'brand new passphrase 99', 'new_password_confirm' => 'brand new passphrase 99']);
        $this->assertRedirect('/settings/security?pw=1', $r);
        $this->assertSame(200, $this->post('/settings/security/backup-codes', ['current_password' => 'brand new passphrase 99'])->status);
        $audit = $this->db->all("SELECT company_id, user_id FROM audit_log WHERE action LIKE 'security.%' ORDER BY id");
        $this->assertCount(2, $audit);
        $this->assertNull($audit[0]['company_id']);
    }

    public function test_the_page_shows_tabs_when_a_company_is_active(): void
    {
        $this->admin(true);
        $this->assertStringContainsString('tabs-pill', $this->get('/settings/security')->body);
    }

    public function test_security_routes_are_protected(): void
    {
        $this->assertRedirect('/login', $this->get('/settings/security'));
        $this->assertSame(419, $this->post('/settings/security/password', [], false)->status);
    }

    public function test_the_current_password_is_recorded_before_it_is_verified(): void
    {
        $this->db = \TestDb::fresh(HookPdo::class);
        $this->store = new \App\Core\ArraySessionStore();
        $this->app = $this->makeApp();
        $u = $this->createUser('o@example.com');
        $this->addMember($u['id'], $this->createCompany());
        $this->loginFully($u['secret'], 'o@example.com');
        $limiter = new \App\Auth\RateLimiter($this->db, 5, 900, fn (): int => $this->now);
        for ($i = 0; $i < 4; $i++) {
            $limiter->hit('security:user:' . $u['id']);
        }
        // Another guess records its hit between our check and our own hit; the 6th must still not pass.
        $pdo = (new \ReflectionProperty($this->db, 'pdo'))->getValue($this->db);
        $raced = false;
        $pdo->onPrepare = function (string $sql) use (&$raced, $limiter, $u): void {
            if (!$raced && str_starts_with($sql, 'INSERT INTO login_attempts')) {
                $raced = true;
                $limiter->hit('security:user:' . $u['id']);
            }
        };
        $this->assertSame(429, $this->post('/settings/security/backup-codes', ['current_password' => self::PASSWORD])->status);
    }

    // ---- admin: temporary password ----

    /** @return array{0: int, 1: int, 2: array{id: int, secret: string}} company, member id */
    private function member(): array
    {
        $c = $this->createCompany('Fremd GmbH');
        $m = $this->createUser('member@example.com');
        $this->addMember($m['id'], $c, 'user');
        return [$c, $m['id'], $m];
    }

    private function tempPassword(int $c, int $uid): \App\Core\Response
    {
        return $this->post("/admin/companies/$c/users/$uid/temp-password");
    }

    public function test_temporary_password_is_shown_once_stored_as_hash_audited_and_forces_a_change(): void
    {
        $this->admin();
        [$c, $uid, $m] = $this->member();
        $oldHash = $this->db->one('SELECT password_hash AS h FROM users WHERE id = ?', [$uid])['h'];
        $r = $this->tempPassword($c, $uid);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('no-store', $r->headers['Cache-Control'] ?? '');
        $this->assertSame(1, preg_match('/<code>([A-Za-z2-9]{4}(?:-[A-Za-z2-9]{4}){3})<\/code>/', $r->body, $mm), 'shown once');
        $temp = $mm[1];
        $row = $this->db->one('SELECT * FROM users WHERE id = ?', [$uid]);
        $this->assertNotSame($oldHash, $row['password_hash']);
        $this->assertTrue(password_verify($temp, $row['password_hash']));
        $this->assertSame(1, (int) $row['must_change_password']);
        $this->assertSame(1, (int) $row['session_epoch']);
        $audit = $this->db->one("SELECT * FROM audit_log WHERE action = 'admin.user_temp_password'");
        $this->assertSame($c, (int) $audit['company_id']);
        $this->assertStringNotContainsString($temp, (string) $audit['detail']);
        $this->assertSame(['company_id' => $c, 'user_id' => $uid], json_decode((string) $audit['detail'], true));
        $this->assertStringNotContainsString($temp, $this->get("/admin/companies/$c")->body, 'not shown again');

        // The member signs in with it and is held on the security page until the password is changed.
        $this->post('/logout');
        $this->assertRedirect('/login/2fa', $this->loginPassword('member@example.com', $temp));
        $this->now += 31;
        $this->assertRedirect('/', $this->post('/login/2fa', ['code' => $this->code($m['secret'])]));
        $this->assertRedirect('/settings/security', $this->get('/'));
        $this->assertRedirect('/settings/security', $this->get('/customers'));
        $page = $this->get('/settings/security');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString($this->app->t('security.must_change'), $page->body);
        $this->assertRedirect('/settings/security?pw=1', $this->post('/settings/security/password', ['current_password' => $temp, 'new_password' => 'brand new passphrase 99', 'new_password_confirm' => 'brand new passphrase 99']));
        $this->assertSame(0, (int) $this->db->one('SELECT must_change_password AS m FROM users WHERE id = ?', [$uid])['m']);
        $this->assertSame(200, $this->get('/customers')->status);
    }

    public function test_temporary_password_lifts_a_login_lockout(): void
    {
        $this->admin();
        [$c, $uid] = $this->member();
        $limiter = new \App\Auth\RateLimiter($this->db, 5, 900, fn (): int => $this->now);
        for ($i = 0; $i < 6; $i++) {
            $limiter->hit('login:email:member@example.com');
        }
        $this->tempPassword($c, $uid);
        $this->assertFalse($limiter->tooMany('login:email:member@example.com'));
    }

    public function test_temporary_password_only_for_members_of_that_company_and_never_for_admins(): void
    {
        $a = $this->admin();
        [$c, $uid] = $this->member();
        $other = $this->createCompany('Andere AG');
        $this->assertSame(404, $this->tempPassword($other, $uid)->status);
        $this->assertSame(404, $this->tempPassword($c, 99999)->status);
        $this->addMember($a['id'], $c, 'owner');
        $this->assertSame(422, $this->tempPassword($c, $a['id'])->status);
        $this->assertSame(0, (int) $this->db->one('SELECT must_change_password AS m FROM users WHERE id = ?', [$a['id']])['m']);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) AS n FROM audit_log WHERE action = 'admin.user_temp_password'")['n']);
    }

    public function test_temporary_password_is_admin_only(): void
    {
        [$c, $uid, $m] = $this->member();
        $this->loginFully($m['secret'], 'member@example.com');
        $this->assertSame(403, $this->tempPassword($c, $uid)->status);
        $this->assertSame(0, (int) $this->db->one('SELECT must_change_password AS m FROM users WHERE id = ?', [$uid])['m']);
    }
}
