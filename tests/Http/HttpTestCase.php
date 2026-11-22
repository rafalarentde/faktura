<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Auth\UserService;
use App\Core\App;
use App\Core\ArraySessionStore;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use OTPHP\TOTP as Otp;
use PHPUnit\Framework\TestCase;

/**
 * Base for HTTP-level tests: drives App::handle() with Request objects, an in-memory
 * session store (shared across requests like a browser cookie) and a fake clock.
 */
abstract class HttpTestCase extends TestCase
{
    protected const PASSWORD = 'correct horse battery';
    protected const IP = '203.0.113.5';

    protected Db $db;
    protected ArraySessionStore $store;
    protected App $app;
    protected int $now = 1_700_000_010;

    protected function setUp(): void
    {
        $this->db = \TestDb::fresh();
        $this->store = new ArraySessionStore();
        $this->app = $this->makeApp();
    }

    /** @param array<string, mixed> $config */
    protected function makeApp(array $config = []): App
    {
        return new App(
            // archive_on_issue is off by default: rendering a PDF per issued document would slow every
            // controller test down; the tests of the archive switch it on.
            $config + ['app_key' => base64_encode(str_repeat('k', 32)), 'debug' => false, 'archive_on_issue' => false],
            $this->db,
            $this->store,
            fn (): int => $this->now,
        );
    }

    /** @param array<string, mixed> $server */
    protected function get(string $path, array $server = []): Response
    {
        return $this->app->handle(new Request('GET', $path, server: $server + ['REMOTE_ADDR' => self::IP]));
    }

    /**
     * POSTs form data; adds the session's CSRF token unless $csrf is false.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $server
     */
    protected function post(string $path, array $data = [], bool $csrf = true, array $server = []): Response
    {
        if ($csrf) {
            // Same token the next rendered form would carry (it is rotated after each login step).
            $data += ['_csrf' => $this->app->csrf()->token()];
        }
        return $this->app->handle(new Request('POST', $path, post: $data, server: $server + ['REMOTE_ADDR' => self::IP]));
    }

    /** Creates a user; with $totp the account has 2FA enabled and the plain secret is returned. */
    protected function createUser(string $email = 'jan@example.pl', bool $totp = true): array
    {
        $id = (new UserService($this->db))->create($email, self::PASSWORD, false);
        $secret = null;
        if ($totp) {
            $secret = $this->app->totp()->newSecret();
            $this->db->exec(
                'UPDATE users SET totp_secret_enc = ?, totp_enabled = 1 WHERE id = ?',
                [$this->app->crypto()->encrypt($secret), $id]
            );
        }
        return ['id' => $id, 'secret' => $secret];
    }

    protected function createCompany(string $name = 'Muster GmbH'): int
    {
        $this->db->exec('INSERT INTO companies (name) VALUES (?)', [$name]);
        return $this->db->lastId();
    }

    protected function addMember(int $userId, int $companyId, string $role = 'owner'): void
    {
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$companyId, $userId, $role]);
    }

    protected function code(string $secret, ?int $at = null): string
    {
        return Otp::createFromSecret($secret)->at($at ?? $this->now);
    }

    protected function loginPassword(string $email = 'jan@example.pl', string $password = self::PASSWORD): Response
    {
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    protected function loginFully(string $secret, string $email = 'jan@example.pl'): void
    {
        $this->assertRedirect('/login/2fa', $this->loginPassword($email));
        $this->assertRedirect('/', $this->post('/login/2fa', ['code' => $this->code($secret)]));
    }

    protected function assertRedirect(string $to, Response $r): void
    {
        $this->assertContains($r->status, [302, 303], 'Expected redirect, got ' . $r->status . ': ' . substr($r->body, 0, 300));
        $this->assertSame($to, $r->headers['Location'] ?? null);
    }
}
