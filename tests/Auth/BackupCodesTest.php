<?php
declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\BackupCodes;
use PHPUnit\Framework\TestCase;

final class BackupCodesTest extends TestCase
{
    public function test_generates_distinct_formatted_codes(): void
    {
        $bc = new BackupCodes(\TestDb::fresh());
        $codes = $bc->generate(1);
        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}$/', $c);
        }
    }

    public function test_database_holds_only_hashes(): void
    {
        $db = \TestDb::fresh();
        $codes = (new BackupCodes($db))->generate(1);
        $rows = $db->all('SELECT code_hash, used_at FROM backup_codes WHERE user_id = 1');
        $this->assertCount(8, $rows);
        foreach ($rows as $r) {
            $this->assertStringStartsWith('$argon2id$', $r['code_hash']);
            $this->assertNull($r['used_at']);
            foreach ($codes as $c) {
                $this->assertStringNotContainsString(str_replace('-', '', $c), $r['code_hash']);
            }
        }
    }

    public function test_used_at_is_stored_in_utc_whatever_the_php_timezone(): void
    {
        $before = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo'); // +09:00: a local date() would be 9 hours ahead
        try {
            $db = \TestDb::fresh();
            $bc = new BackupCodes($db);
            $code = $bc->generate(1)[0];
            $t0 = time();
            $this->assertTrue($bc->consume(1, $code));
            $used = (string) $db->one('SELECT used_at FROM backup_codes WHERE used_at IS NOT NULL')['used_at'];
            $ts = (new \DateTimeImmutable($used, new \DateTimeZone('UTC')))->getTimestamp();
            $this->assertEqualsWithDelta($t0, $ts, 5);
        } finally {
            date_default_timezone_set($before);
        }
    }

    public function test_consume_works_exactly_once(): void
    {
        $db = \TestDb::fresh();
        $bc = new BackupCodes($db);
        $codes = $bc->generate(1, 3);
        $this->assertTrue($bc->consume(1, $codes[0]));
        $this->assertFalse($bc->consume(1, $codes[0]));
        $this->assertTrue($bc->consume(1, $codes[1]));
        $this->assertSame(2, (int) $db->one('SELECT COUNT(*) AS n FROM backup_codes WHERE used_at IS NOT NULL')['n']);
    }

    public function test_consume_normalises_input(): void
    {
        $bc = new BackupCodes(\TestDb::fresh());
        $c = $bc->generate(1, 2);
        $this->assertTrue($bc->consume(1, ' ' . strtolower(str_replace('-', '', $c[0])) . ' '));
        $this->assertTrue($bc->consume(1, strtolower(substr($c[1], 0, 4)) . ' ' . substr($c[1], 5)));
    }

    public function test_rejects_unknown_and_empty_codes(): void
    {
        $bc = new BackupCodes(\TestDb::fresh());
        $bc->generate(1);
        $this->assertFalse($bc->consume(1, 'AAAA-AAAA'));
        $this->assertFalse($bc->consume(1, ''));
        $this->assertFalse($bc->consume(1, '----'));
    }

    public function test_code_of_one_user_never_works_for_another(): void
    {
        $bc = new BackupCodes(\TestDb::fresh());
        $a = $bc->generate(1);
        $b = $bc->generate(2);
        $this->assertFalse($bc->consume(2, $a[0]));
        $this->assertFalse($bc->consume(1, $b[0]));
        $this->assertTrue($bc->consume(1, $a[0]));
        $this->assertTrue($bc->consume(2, $b[0]));
    }

    public function test_regenerating_replaces_previous_set(): void
    {
        $db = \TestDb::fresh();
        $bc = new BackupCodes($db);
        $old = $bc->generate(1);
        $bc->consume(1, $old[0]);
        $other = $bc->generate(2, 2);
        $new = $bc->generate(1);
        $this->assertSame(8, (int) $db->one('SELECT COUNT(*) AS n FROM backup_codes WHERE user_id = 1')['n']);
        $this->assertFalse($bc->consume(1, $old[1]));
        $this->assertTrue($bc->consume(1, $new[0]));
        $this->assertTrue($bc->consume(2, $other[0]));
    }
}
