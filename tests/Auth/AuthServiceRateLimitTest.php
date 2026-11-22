<?php
declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\AuthService;
use App\Auth\BackupCodes;
use App\Auth\RateLimiter;
use App\Auth\Totp;
use App\Auth\UserService;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Migrator;
use PHPUnit\Framework\TestCase;
use Tests\Support\HookPdo;

/** The auth limits are hit-first: the attempt is recorded BEFORE the password/code is verified. */
final class AuthServiceRateLimitTest extends TestCase
{
    private const PW = 'correct horse battery';
    private int $now = 1_700_000_000;
    private HookPdo $pdo;
    private Db $db;
    private AuthService $auth;
    private RateLimiter $limiter;
    private Totp $totp;
    private Crypto $crypto;
    /** @var list<int> login_attempts rows seen at the moment a verification query ran */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->pdo = new HookPdo('sqlite::memory:');
        $this->db = new Db($this->pdo, 'sqlite');
        (new Migrator($this->db, __DIR__ . '/../../migrations'))->run();
        $this->limiter = new RateLimiter($this->db, 5, 900, fn (): int => $this->now);
        $this->totp = new Totp();
        $this->crypto = new Crypto(base64_encode(str_repeat('k', 32)));
        $this->auth = new AuthService($this->db, $this->limiter, $this->totp, new BackupCodes($this->db), $this->crypto, fn (): int => $this->now);
    }

    private function attempts(): int
    {
        return (int) $this->db->one('SELECT COUNT(*) AS n FROM login_attempts')['n'];
    }

    /** Records how many attempts exist when the given verification query is prepared. */
    private function watch(string $needle): void
    {
        $this->pdo->onPrepare = function (string $sql) use ($needle): void {
            if (str_contains($sql, $needle)) {
                $this->seen[] = $this->attempts();
            }
        };
    }

    public function test_password_attempt_is_recorded_before_the_password_is_verified(): void
    {
        (new UserService($this->db))->create('a@example.pl', self::PW, false);
        $this->watch('SELECT id, password_hash, totp_enabled FROM users');
        $this->auth->attemptPassword('a@example.pl', self::PW, '203.0.113.1');
        $this->assertSame([2], $this->seen, 'email and IP hit exist before the user row is even read');
    }

    public function test_concurrent_password_guesses_cannot_all_pass_the_limit(): void
    {
        (new UserService($this->db))->create('a@example.pl', self::PW, false);
        // Four other guesses are in flight: hit recorded, verdict not yet in.
        for ($i = 0; $i < 4; $i++) {
            $this->limiter->hit('login:email:a@example.pl');
        }
        $this->assertTrue($this->auth->attemptPassword('a@example.pl', 'wrong wrong wrong', '203.0.113.1')->status === \App\Auth\AuthResult::INVALID, 'the 5th is the last one let through');
        // The 6th is refused even with the right password: its hit was recorded first.
        $this->assertSame(\App\Auth\AuthResult::LOCKED, $this->auth->attemptPassword('a@example.pl', self::PW, '203.0.113.1')->status);
    }

    public function test_successful_password_clears_the_email_counter(): void
    {
        (new UserService($this->db))->create('a@example.pl', self::PW, false);
        for ($i = 0; $i < 3; $i++) {
            $this->auth->attemptPassword('a@example.pl', 'wrong wrong wrong', '203.0.113.1');
        }
        $this->assertTrue($this->auth->attemptPassword('a@example.pl', self::PW, '203.0.113.1')->isOk());
        $this->assertFalse($this->limiter->tooMany('login:email:a@example.pl'));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM login_attempts WHERE `key` = ?', [hash('sha256', 'login:email:a@example.pl')])['n']);
    }

    public function test_second_factor_attempt_is_recorded_before_the_code_is_checked(): void
    {
        $id = (new UserService($this->db))->create('a@example.pl', self::PW, false);
        $secret = $this->totp->newSecret();
        $this->db->exec('UPDATE users SET totp_secret_enc = ?, totp_enabled = 1 WHERE id = ?', [$this->crypto->encrypt($secret), $id]);
        $this->watch('SELECT id, email, totp_secret_enc, totp_enabled FROM users');
        $this->auth->verifySecondFactor($id, '000000', '203.0.113.1');
        $this->assertSame([1], $this->seen);

        $this->seen = [];
        $this->pdo->onPrepare = null;
        for ($i = 0; $i < 3; $i++) {
            $this->limiter->hit('2fa:user:' . $id); // in-flight guesses
        }
        $this->assertSame(\App\Auth\AuthResult::INVALID, $this->auth->verifySecondFactor($id, '000000', '203.0.113.1')->status); // 5th hit
        $good = \OTPHP\TOTP::createFromSecret($secret)->at($this->now);
        $this->assertTrue($this->auth->verifySecondFactor($id, $good, '203.0.113.1')->status === \App\Auth\AuthResult::LOCKED, 'a correct code does not rescue the 6th attempt');
    }

    public function test_successful_second_factor_clears_the_counter(): void
    {
        $id = (new UserService($this->db))->create('a@example.pl', self::PW, false);
        $secret = $this->totp->newSecret();
        $this->db->exec('UPDATE users SET totp_secret_enc = ?, totp_enabled = 1 WHERE id = ?', [$this->crypto->encrypt($secret), $id]);
        $this->auth->verifySecondFactor($id, '000000', '203.0.113.1');
        $this->assertTrue($this->auth->verifySecondFactor($id, \OTPHP\TOTP::createFromSecret($secret)->at($this->now), '203.0.113.1')->isOk());
        $this->assertSame(0, $this->attempts());
    }

    public function test_totp_setup_confirmation_is_hit_first(): void
    {
        $id = (new UserService($this->db))->create('a@example.pl', self::PW, false);
        $this->auth->pendingTotpSecret($id);
        $this->watch('SELECT totp_secret_enc, totp_enabled FROM users');
        $this->auth->confirmTotpSetup($id, '000000');
        $this->assertSame([1], $this->seen);
    }
}
