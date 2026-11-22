<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\ArraySessionStore;
use App\Core\Request;
use App\Core\Response;

/** users.session_epoch: credential changes end every OTHER session of the user; the acting session stays. */
final class SessionEpochTest extends HttpTestCase
{
    /** @var array{id: int, secret: string} */
    private array $user;
    private int $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany();
        $this->user = $this->createUser('owner@example.com');
        $this->addMember($this->user['id'], $this->company, 'owner');
    }

    private function epoch(?int $id = null): int
    {
        return (int) $this->db->one('SELECT session_epoch AS e FROM users WHERE id = ?', [$id ?? $this->user['id']])['e'];
    }

    /** @return array{0: App, 1: ArraySessionStore} a second browser (own session store) on the same database */
    private function device(): array
    {
        $store = new ArraySessionStore();
        $app = new App(['app_key' => base64_encode(str_repeat('k', 32)), 'debug' => false], $this->db, $store, fn (): int => $this->now);
        return [$app, $store];
    }

    /** @param array<string, mixed> $data */
    private function on(App $app, string $method, string $path, array $data = []): Response
    {
        if ($method === 'POST') {
            $data += ['_csrf' => $app->csrf()->token()];
        }
        return $app->handle(new Request($method, $path, post: $method === 'POST' ? $data : [], server: ['REMOTE_ADDR' => self::IP]));
    }

    private function loginOn(App $app, string $email = 'owner@example.com', ?string $secret = null): void
    {
        $this->now += 31; // a TOTP step is spent once
        $this->assertRedirect('/login/2fa', $this->on($app, 'POST', '/login', ['email' => $email, 'password' => self::PASSWORD]));
        $this->assertRedirect('/', $this->on($app, 'POST', '/login/2fa', ['code' => $this->code($secret ?? $this->user['secret'])]));
    }

    private function assertLoggedIn(App $app): void
    {
        $this->assertSame(200, $this->on($app, 'GET', '/')->status);
    }

    private function assertLoggedOut(App $app): void
    {
        $this->assertRedirect('/login', $this->on($app, 'GET', '/'));
    }

    public function test_the_column_exists_with_default_zero(): void
    {
        $this->assertSame(0, $this->epoch());
    }

    public function test_login_stores_the_epoch_and_a_changed_epoch_logs_the_session_out(): void
    {
        $this->loginFully($this->user['secret'], 'owner@example.com');
        $this->assertSame(0, $this->app->session()->get('_epoch'));
        $this->assertSame(200, $this->get('/')->status);
        $this->db->exec('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?', [$this->user['id']]);
        $this->assertRedirect('/login', $this->get('/'));
        $this->assertNull($this->app->session()->userId(), 'the session is destroyed, not just refused');
        $this->assertRedirect('/login', $this->get('/'));
    }

    public function test_a_session_without_an_epoch_is_refused(): void
    {
        $this->loginFully($this->user['secret'], 'owner@example.com');
        $this->app->session()->remove('_epoch'); // a session from before the migration
        $this->assertRedirect('/login', $this->get('/'));
    }

    public function test_password_change_logs_out_other_devices_but_not_this_one(): void
    {
        $this->loginFully($this->user['secret'], 'owner@example.com');
        [$other] = $this->device();
        $this->loginOn($other);
        $this->assertLoggedIn($other);

        $r = $this->post('/settings/security/password', ['current_password' => self::PASSWORD, 'new_password' => 'brand new passphrase 99', 'new_password_confirm' => 'brand new passphrase 99']);
        $this->assertRedirect('/settings/security?pw=1', $r);
        $this->assertSame(1, $this->epoch());
        $this->assertLoggedOut($other);
        $this->assertSame(200, $this->get('/settings/security')->status, 'the acting session stays logged in');
        $this->assertLoggedIn($this->app);
    }

    public function test_backup_code_regeneration_logs_out_other_devices_but_not_this_one(): void
    {
        $this->loginFully($this->user['secret'], 'owner@example.com');
        [$other] = $this->device();
        $this->loginOn($other);
        $this->assertSame(200, $this->post('/settings/security/backup-codes', ['current_password' => self::PASSWORD])->status);
        $this->assertSame(1, $this->epoch());
        $this->assertLoggedOut($other);
        $this->assertLoggedIn($this->app);
    }

    public function test_admin_2fa_reset_logs_the_user_out_everywhere(): void
    {
        $admin = $this->createUser('admin@example.com');
        $this->db->exec('UPDATE users SET is_admin = 1 WHERE id = ?', [$admin['id']]);
        $this->loginOn($this->app, 'admin@example.com', $admin['secret']);
        [$victim] = $this->device();
        $this->loginOn($victim);
        $this->assertLoggedIn($victim);
        $this->assertRedirect("/admin/companies/{$this->company}", $this->post("/admin/companies/{$this->company}/users/{$this->user['id']}/reset-2fa"));
        $this->assertSame(1, $this->epoch());
        $this->assertSame(0, $this->epoch($admin['id']));
        $this->assertLoggedOut($victim);
        $this->assertSame(200, $this->get('/admin/companies')->status, 'the admin keeps his own session');
    }

    public function test_enabling_two_factor_bumps_the_epoch_regenerates_the_session_and_rotates_csrf(): void
    {
        $this->createUser('new@example.com', false);
        $this->assertRedirect('/2fa/setup', $this->loginPassword('new@example.com'));
        [$other] = $this->device();
        $this->assertRedirect('/2fa/setup', $this->on($other, 'POST', '/login', ['email' => 'new@example.com', 'password' => self::PASSWORD]));
        $id = (int) $this->db->one("SELECT id FROM users WHERE email = 'new@example.com'")['id'];
        $this->get('/2fa/setup');
        $secret = $this->app->auth()->pendingTotpSecret($id);
        $regen = $this->store->regenerations();
        $oldCsrf = $this->app->csrf()->token();
        $this->assertSame(200, $this->post('/2fa/setup', ['code' => $this->code($secret)])->status);
        $this->assertSame(1, $this->epoch($id));
        $this->assertGreaterThan($regen, $this->store->regenerations());
        $this->assertNotSame($oldCsrf, $this->app->csrf()->token());
        $this->assertSame(1, $this->app->session()->get('_epoch'));
        $this->assertLoggedOut($other);
    }
}
