<?php
declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private int $now = 1_700_000_000;

    private function limiter(\App\Core\Db $db, int $max = 5, int $window = 900): RateLimiter
    {
        return new RateLimiter($db, $max, $window, fn (): int => $this->now);
    }

    public function test_blocks_after_max_hits(): void
    {
        $rl = $this->limiter(\TestDb::fresh());
        for ($i = 0; $i < 4; $i++) {
            $rl->hit('a@b.pl');
            $this->assertFalse($rl->tooMany('a@b.pl'));
        }
        $rl->hit('a@b.pl');
        $this->assertTrue($rl->tooMany('a@b.pl'));
    }

    public function test_attempt_hits_first_and_accepts_at_most_max_per_window(): void
    {
        $rl = $this->limiter(\TestDb::fresh(), 3, 3600);
        $this->assertSame([true, true, true, false, false], array_map(fn (): bool => $rl->attempt('k'), range(1, 5)));
        $this->assertTrue($rl->attempt('other'), 'keys are independent');
        $this->now += 3601;
        $this->assertTrue($rl->attempt('k'), 'a new window starts empty');
    }

    public function test_attempt_counts_hits_recorded_by_requests_still_in_flight(): void
    {
        $db = \TestDb::fresh();
        $rl = $this->limiter($db, 3, 3600);
        foreach ([1, 2, 3] as $_) {
            $rl->hit('k'); // three concurrent requests recorded their hit but have not finished
        }
        $this->assertFalse($rl->attempt('k'));
    }

    public function test_keys_are_independent_and_clear_resets(): void
    {
        $rl = $this->limiter(\TestDb::fresh());
        for ($i = 0; $i < 5; $i++) {
            $rl->hit('x');
        }
        $rl->hit('y');
        $this->assertTrue($rl->tooMany('x'));
        $this->assertFalse($rl->tooMany('y'));
        $rl->clear('x');
        $this->assertFalse($rl->tooMany('x'));
        $this->assertFalse($rl->tooMany('x'));
        $rl->clear('never-hit');
    }

    public function test_old_entries_do_not_count_and_are_pruned_on_hit(): void
    {
        $db = \TestDb::fresh();
        $rl = $this->limiter($db);
        for ($i = 0; $i < 5; $i++) {
            $rl->hit('k');
        }
        $this->assertTrue($rl->tooMany('k'));
        $this->now += 899;
        $this->assertTrue($rl->tooMany('k'));
        $this->now += 2;
        $this->assertFalse($rl->tooMany('k'));
        $rl->hit('k');
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) AS n FROM login_attempts')['n']);
        $this->assertFalse($rl->tooMany('k'));
    }

    public function test_keys_stored_hashed(): void
    {
        $db = \TestDb::fresh();
        $this->limiter($db)->hit('victim@example.pl');
        $row = $db->one('SELECT `key` FROM login_attempts');
        $this->assertSame(hash('sha256', 'victim@example.pl'), $row['key']);
    }

    public function test_default_clock_and_limits(): void
    {
        $db = \TestDb::fresh();
        $rl = new RateLimiter($db);
        for ($i = 0; $i < 5; $i++) {
            $rl->hit('k');
        }
        $this->assertTrue($rl->tooMany('k'));
        $this->assertEqualsWithDelta(time(), (int) $db->one('SELECT MAX(at) AS m FROM login_attempts')['m'], 5);
    }
}
