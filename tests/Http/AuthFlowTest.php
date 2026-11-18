<?php
declare(strict_types=1);

namespace Tests\Http;

final class AuthFlowTest extends HttpTestCase
{
    public function test_login_page_renders_form_with_csrf_and_autofocus(): void
    {
        $r = $this->get('/login');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('name="_csrf"', $r->body);
        $this->assertStringContainsString('autofocus', $r->body);
        $this->assertStringContainsString('name="email"', $r->body);
        $this->assertStringContainsString('name="password"', $r->body);
    }

    public function test_anonymous_is_redirected_to_login(): void
    {
        $this->assertRedirect('/login', $this->get('/'));
        $this->assertRedirect('/login', $this->get('/2fa/setup'));
    }

    public function test_password_ok_requires_second_step(): void
    {
        ['id' => $id] = $this->createUser();
        $this->assertRedirect('/login/2fa', $this->loginPassword());
        $this->assertSame('password_ok', $this->app->session()->loginStage());
        $this->assertSame($id, $this->app->session()->userId());
        $this->assertRedirect('/login/2fa', $this->get('/'));
        $this->assertRedirect('/login/2fa', $this->get('/2fa/setup'));

        $page = $this->get('/login/2fa');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('autocomplete="one-time-code"', $page->body);
        $this->assertStringContainsString('inputmode="numeric"', $page->body);
    }

    public function test_valid_totp_completes_login(): void
    {
        ['id' => $id, 'secret' => $secret] = $this->createUser();
        $this->loginFully($secret);
        $this->assertSame('full', $this->app->session()->loginStage());
        $this->assertSame($id, $this->app->session()->userId());
        $home = $this->get('/');
        $this->assertSame(200, $home->status);
        $this->assertStringContainsString('jan@example.pl', $home->body);
        $this->assertSame(intdiv($this->now, 30), (int) $this->db->one('SELECT totp_last_step FROM users')['totp_last_step']);
    }

    public function test_wrong_totp_is_rejected(): void
    {
        ['secret' => $secret] = $this->createUser();
        $this->loginPassword();
        $wrong = $this->code($secret) === '000000' ? '111111' : '000000';
        $r = $this->post('/login/2fa', ['code' => $wrong]);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString($this->app->t('login.error_code'), $r->body);
        $this->assertSame('password_ok', $this->app->session()->loginStage());
    }

    public function test_replayed_totp_code_fails(): void
    {
        ['secret' => $secret] = $this->createUser();
        $this->loginFully($secret);
        $used = $this->code($secret);
        $this->assertRedirect('/login', $this->post('/logout'));

        $this->loginPassword();
        $r = $this->post('/login/2fa', ['code' => $used]);
        $this->assertSame(422, $r->status);
        $this->assertSame('password_ok', $this->app->session()->loginStage());

        // A code from an earlier step (still inside the ±1 window) fails as well.
        $earlier = $this->code($secret, $this->now - 30);
        if ($earlier !== $used) {
            $this->assertSame(422, $this->post('/login/2fa', ['code' => $earlier])->status);
        }

        $this->now += 30;
        $this->assertRedirect('/', $this->post('/login/2fa', ['code' => $this->code($secret)]));
    }

    public function test_backup_code_works_once(): void
    {
        ['id' => $id] = $this->createUser();
        $codes = $this->app->backupCodes()->generate($id);

        $this->loginPassword();
        $this->assertRedirect('/', $this->post('/login/2fa', ['code' => strtolower($codes[0])]));
        $this->assertSame('full', $this->app->session()->loginStage());
        $this->post('/logout');

        $this->loginPassword();
        $this->assertSame(422, $this->post('/login/2fa', ['code' => $codes[0]])->status);
        $this->assertRedirect('/', $this->post('/login/2fa', ['code' => $codes[1]]));
    }

    public function test_wrong_password_and_unknown_email_give_same_message(): void
    {
        $this->createUser();
        $msg = $this->app->t('login.error_invalid');
        $wrongPw = $this->loginPassword('jan@example.pl', 'wrong password!!');
        $unknown = $this->loginPassword('nobody@example.pl', self::PASSWORD);
        $this->assertStringContainsString($msg, $wrongPw->body);
        $this->assertStringContainsString($msg, $unknown->body);
        $this->assertSame('none', $this->app->session()->loginStage());
    }

    public function test_user_enumeration_same_status_and_body(): void
    {
        $this->createUser();
        $wrongPw = $this->loginPassword('jan@example.pl', 'wrong password!!');
        $unknown = $this->loginPassword('nobody@example.pl', 'wrong password!!');
        $this->assertSame($wrongPw->status, $unknown->status);
        // The typed email is echoed back into the form; apart from that the pages are identical.
        $this->assertSame(
            str_replace('jan@example.pl', 'EMAIL', $wrongPw->body),
            str_replace('nobody@example.pl', 'EMAIL', $unknown->body)
        );
    }

    public function test_email_is_trimmed_and_lowercased(): void
    {
        ['secret' => $secret] = $this->createUser();
        $this->assertRedirect('/login/2fa', $this->loginPassword('  Jan@Example.PL ', self::PASSWORD));
    }

    public function test_post_without_csrf_is_rejected(): void
    {
        $this->createUser();
        $r = $this->post('/login', ['email' => 'jan@example.pl', 'password' => self::PASSWORD], csrf: false);
        $this->assertSame(419, $r->status);
        $this->assertSame('none', $this->app->session()->loginStage());

        $r = $this->post('/login', ['email' => 'jan@example.pl', 'password' => self::PASSWORD, '_csrf' => str_repeat('a', 64)], csrf: false);
        $this->assertSame(419, $r->status);

        $r = $this->post('/login', ['email' => 'jan@example.pl', 'password' => self::PASSWORD, '_csrf' => ['x']], csrf: false);
        $this->assertSame(419, $r->status);
    }

    public function test_logout_without_csrf_is_rejected(): void
    {
        ['secret' => $secret] = $this->createUser();
        $this->loginFully($secret);
        $this->assertSame(419, $this->post('/logout', csrf: false)->status);
        $this->assertSame('full', $this->app->session()->loginStage());
    }

    public function test_logout_destroys_session(): void
    {
        ['secret' => $secret] = $this->createUser();
        $this->loginFully($secret);
        $this->assertRedirect('/login', $this->post('/logout'));
        $this->assertTrue($this->store->wasDestroyed());
        $this->assertNull($this->app->session()->userId());
        $this->assertRedirect('/login', $this->get('/'));
    }

    public function test_five_failures_lock_further_attempts(): void
    {
        $this->createUser();
        for ($i = 0; $i < 5; $i++) {
            $r = $this->loginPassword('jan@example.pl', 'wrong password!!');
            $this->assertStringContainsString($this->app->t('login.error_invalid'), $r->body);
        }
        $r = $this->loginPassword('jan@example.pl', 'wrong password!!');
        $this->assertSame(429, $r->status);
        $this->assertStringContainsString($this->app->t('login.error_locked'), $r->body);
    }

    public function test_locked_user_with_correct_password_cannot_log_in_until_window_passes(): void
    {
        ['secret' => $secret] = $this->createUser();
        for ($i = 0; $i < 5; $i++) {
            $this->loginPassword('jan@example.pl', 'wrong password!!');
        }
        $r = $this->loginPassword();
        $this->assertSame(429, $r->status);
        $this->assertSame('none', $this->app->session()->loginStage());

        $this->now += 14 * 60;
        $this->assertSame(429, $this->loginPassword()->status);

        $this->now += 61;
        $this->assertRedirect('/login/2fa', $this->loginPassword());
    }

    public function test_failures_from_one_ip_lock_other_emails(): void
    {
        $this->createUser('other@example.pl');
        for ($i = 0; $i < 5; $i++) {
            $this->loginPassword("nobody$i@example.pl", 'wrong password!!');
        }
        $this->assertSame(429, $this->loginPassword('other@example.pl')->status);
    }

    public function test_successful_login_clears_failure_counters(): void
    {
        ['secret' => $secret] = $this->createUser();
        for ($i = 0; $i < 4; $i++) {
            $this->loginPassword('jan@example.pl', 'wrong password!!');
        }
        $this->loginFully($secret);
        $this->post('/logout');
        for ($i = 0; $i < 4; $i++) {
            $this->loginPassword('jan@example.pl', 'wrong password!!');
        }
        $this->now += 30;
        $this->loginFully($secret);
    }

    public function test_second_factor_is_rate_limited(): void
    {
        ['id' => $id, 'secret' => $secret] = $this->createUser();
        $codes = $this->app->backupCodes()->generate($id);
        $this->loginPassword();
        $wrong = $this->code($secret) === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(422, $this->post('/login/2fa', ['code' => $wrong])->status);
        }
        $r = $this->post('/login/2fa', ['code' => $this->code($secret)]);
        $this->assertSame(429, $r->status);
        $this->assertStringContainsString($this->app->t('login.error_locked'), $r->body);
        // Backup codes go through the same limit and are not consumed while locked.
        $this->assertSame(429, $this->post('/login/2fa', ['code' => $codes[0]])->status);
        $this->assertSame(8, (int) $this->db->one('SELECT COUNT(*) AS n FROM backup_codes WHERE used_at IS NULL')['n']);
        $this->assertSame('password_ok', $this->app->session()->loginStage());
    }

    public function test_account_without_2fa_is_forced_to_setup(): void
    {
        $this->createUser(totp: false);
        $this->assertRedirect('/2fa/setup', $this->loginPassword());
        $this->assertSame('full', $this->app->session()->loginStage());
        $this->assertRedirect('/2fa/setup', $this->get('/'));
        $this->assertSame(200, $this->get('/2fa/setup')->status);
    }

    public function test_2fa_setup_flow(): void
    {
        ['id' => $id] = $this->createUser(totp: false);
        $this->loginPassword();

        $page = $this->get('/2fa/setup');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('src="data:image/', $page->body);
        $row = $this->db->one('SELECT totp_secret_enc, totp_enabled FROM users WHERE id = ?', [$id]);
        $secret = $this->app->crypto()->decrypt((string) $row['totp_secret_enc']);
        $this->assertNotSame($secret, $row['totp_secret_enc']);
        $this->assertStringContainsString($secret, $page->body);
        $this->assertSame(0, (int) $row['totp_enabled']);
        $this->assertStringContainsString('autocomplete="one-time-code"', $page->body);

        // Reloading keeps the same pending secret.
        $this->get('/2fa/setup');
        $this->assertSame($row['totp_secret_enc'], $this->db->one('SELECT totp_secret_enc FROM users')['totp_secret_enc']);

        $wrong = $this->code($secret) === '000000' ? '111111' : '000000';
        $this->assertSame(422, $this->post('/2fa/setup', ['code' => $wrong])->status);
        $this->assertSame(0, (int) $this->db->one('SELECT totp_enabled FROM users')['totp_enabled']);

        $done = $this->post('/2fa/setup', ['code' => $this->code($secret)]);
        $this->assertSame(200, $done->status);
        $this->assertSame(1, (int) $this->db->one('SELECT totp_enabled FROM users')['totp_enabled']);
        $this->assertSame(8, (int) $this->db->one('SELECT COUNT(*) AS n FROM backup_codes WHERE user_id = ?', [$id])['n']);
        preg_match_all('/\b[A-Z2-9]{4}-[A-Z2-9]{4}\b/', $done->body, $m);
        $this->assertCount(8, array_unique($m[0]));
        $this->assertTrue($this->app->backupCodes()->consume($id, $m[0][0]));

        $this->assertSame(200, $this->get('/')->status);
        $this->assertRedirect('/', $this->get('/2fa/setup'));

        // The confirming code cannot be replayed at the next login.
        $this->post('/logout');
        $this->loginPassword();
        $this->assertSame(422, $this->post('/login/2fa', ['code' => $this->code($secret)])->status);
    }

    public function test_2fa_setup_rejects_replayed_code(): void
    {
        ['id' => $id] = $this->createUser(totp: false);
        $this->loginPassword();
        $this->get('/2fa/setup');
        $secret = $this->app->crypto()->decrypt((string) $this->db->one('SELECT totp_secret_enc FROM users')['totp_secret_enc']);
        $this->db->exec('UPDATE users SET totp_last_step = ? WHERE id = ?', [intdiv($this->now, 30), $id]);
        $this->assertSame(422, $this->post('/2fa/setup', ['code' => $this->code($secret)])->status);
        $this->now += 30;
        $this->assertSame(200, $this->post('/2fa/setup', ['code' => $this->code($secret)])->status);
    }

    public function test_session_cookie_flags(): void
    {
        $this->get('/login', ['HTTPS' => 'on']);
        $p = $this->store->cookieParams();
        $this->assertSame('faktura_sid', $this->store->name());
        $this->assertTrue($p['httponly']);
        $this->assertSame('Lax', $p['samesite']);
        $this->assertTrue($p['secure']);
        $this->assertSame('/', $p['path']);
        $this->assertSame(0, $p['lifetime']);

        $this->get('/login');
        $this->assertFalse($this->store->cookieParams()['secure']);
    }

    public function test_session_id_changes_after_each_login_stage(): void
    {
        ['secret' => $secret] = $this->createUser();
        $this->get('/login');
        $id0 = $this->store->id();
        $n0 = $this->store->regenerations();

        $this->loginPassword();
        $this->assertSame($n0 + 1, $this->store->regenerations());
        $id1 = $this->store->id();
        $this->assertNotSame($id0, $id1);

        $this->post('/login/2fa', ['code' => $this->code($secret)]);
        $this->assertSame($n0 + 2, $this->store->regenerations());
        $this->assertNotSame($id1, $this->store->id());
    }

    public function test_idle_timeout_expires_session(): void
    {
        ['secret' => $secret] = $this->createUser();
        $this->loginFully($secret);
        $this->now += 7200;
        $this->assertSame(200, $this->get('/')->status);
        $this->now += 7201;
        $this->assertRedirect('/login', $this->get('/'));
        $this->assertNull($this->app->session()->userId());
    }

    public function test_absolute_lifetime_expires_session(): void
    {
        ['secret' => $secret] = $this->createUser();
        $this->loginFully($secret);
        for ($h = 1; $h <= 11; $h++) {
            $this->now += 3600;
            $this->assertSame(200, $this->get('/')->status, "hour $h");
        }
        $this->now += 3601;
        $this->assertRedirect('/login', $this->get('/'));
    }

    public function test_password_is_rehashed_with_argon2id(): void
    {
        ['id' => $id] = $this->createUser();
        $this->db->exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash(self::PASSWORD, PASSWORD_BCRYPT), $id]);
        $this->assertRedirect('/login/2fa', $this->loginPassword());
        $hash = (string) $this->db->one('SELECT password_hash FROM users')['password_hash'];
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue(password_verify(self::PASSWORD, $hash));
    }

    public function test_deleted_user_session_is_dropped(): void
    {
        ['secret' => $secret] = $this->createUser();
        $this->loginFully($secret);
        $this->db->exec('DELETE FROM users');
        $this->assertRedirect('/login', $this->get('/'));
        $this->assertNull($this->app->session()->userId());
    }

    public function test_login_pages_redirect_when_already_at_a_later_stage(): void
    {
        ['secret' => $secret] = $this->createUser();
        $this->assertRedirect('/login', $this->get('/login/2fa'));
        $this->loginPassword();
        $this->assertRedirect('/login/2fa', $this->get('/login'));
        $this->post('/login/2fa', ['code' => $this->code($secret)]);
        $this->assertRedirect('/', $this->get('/login'));
        $this->assertRedirect('/', $this->get('/login/2fa'));
    }
}
