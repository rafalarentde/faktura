<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\ArraySessionStore;
use App\Core\Csrf;
use App\Core\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    private int $now = 1_000_000;

    private function session(ArraySessionStore $store, bool $secure = false): Session
    {
        $s = new Session($store, $secure, fn (): int => $this->now);
        $s->start();
        return $s;
    }

    /** A fully logged-in session (the password-only stage has its own 10 minute limit). */
    private function login(ArraySessionStore $store, int $userId): void
    {
        $s = $this->session($store);
        $s->passwordOk($userId);
        $s->fullLogin();
    }

    public function test_stages(): void
    {
        $store = new ArraySessionStore();
        $s = $this->session($store);
        $this->assertSame('none', $s->loginStage());
        $this->assertNull($s->userId());
        $s->passwordOk(7);
        $this->assertSame('password_ok', $s->loginStage());
        $this->assertSame(7, $s->userId());
        $s->fullLogin();
        $this->assertSame('full', $s->loginStage());
        $this->assertSame(2, $store->regenerations());
        $s->logout();
        $this->assertSame('none', $s->loginStage());
        $this->assertTrue($store->wasDestroyed());
    }

    public function test_password_stage_expires_after_ten_minutes_back_to_login(): void
    {
        $store = new ArraySessionStore();
        $this->session($store)->passwordOk(5);
        $this->now += Session::PASSWORD_STAGE_TTL;
        $s = $this->session($store);
        $this->assertSame('password_ok', $s->loginStage(), 'exactly at the limit it is still valid');
        $this->now += 1;
        $s = $this->session($store);
        $this->assertSame('none', $s->loginStage());
        $this->assertNull($s->userId());
    }

    public function test_the_password_stage_clock_is_not_extended_by_activity(): void
    {
        $store = new ArraySessionStore();
        $this->session($store)->passwordOk(5);
        for ($i = 0; $i < 4; $i++) {
            $this->now += 200; // requests every 200 s keep the idle timer alive, not the 10 minute stage
            $s = $this->session($store);
        }
        $this->assertSame('none', $s->loginStage());
    }

    public function test_a_full_login_does_not_expire_with_the_password_stage_ttl(): void
    {
        $store = new ArraySessionStore();
        $s = $this->session($store);
        $s->passwordOk(5);
        $s->fullLogin(0);
        $this->now += Session::PASSWORD_STAGE_TTL + 1;
        $this->assertSame('full', $this->session($store)->loginStage());
    }

    public function test_full_login_requires_password_stage(): void
    {
        $s = $this->session(new ArraySessionStore());
        $this->expectException(\LogicException::class);
        $s->fullLogin();
    }

    public function test_cookie_params(): void
    {
        $store = new ArraySessionStore();
        $this->session($store, true);
        $this->assertSame(Session::COOKIE_NAME, $store->name());
        $this->assertSame(
            ['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'],
            $store->cookieParams()
        );
    }

    public function test_idle_timeout(): void
    {
        $store = new ArraySessionStore();
        $this->login($store, 1);
        $this->now += Session::IDLE_TIMEOUT;
        $this->assertSame(1, $this->session($store)->userId());
        $this->now += Session::IDLE_TIMEOUT + 1;
        $this->assertNull($this->session($store)->userId());
    }

    public function test_absolute_lifetime_counts_from_login(): void
    {
        $store = new ArraySessionStore();
        $this->session($store);
        $this->now += 10 * 3600; // anonymous session ages; login resets the absolute clock
        $this->login($store, 1);
        for ($i = 0; $i < 12; $i++) {
            $this->now += 3600;
            $this->assertSame(1, $this->session($store)->userId(), "hour $i");
        }
        $this->now += 1;
        $this->assertNull($this->session($store)->userId());
    }

    public function test_csrf_token(): void
    {
        $s = $this->session(new ArraySessionStore());
        $c = new Csrf($s);
        $t = $c->token();
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $t);
        $this->assertSame($t, $c->token());
        $this->assertTrue($c->check($t));
        $this->assertFalse($c->check(''));
        $this->assertFalse($c->check(strtoupper($t)));
        $c->rotate();
        $this->assertFalse($c->check($t));
        $this->assertNotSame($t, $c->token());
    }

    public function test_csrf_check_without_token_fails(): void
    {
        $this->assertFalse((new Csrf($this->session(new ArraySessionStore())))->check(''));
    }
}
