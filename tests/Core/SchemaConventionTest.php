<?php
declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/** Money/quantity/timestamp columns must be BIGINT: MySQL INTEGER is 32 bit, SQLite would hide an overflow. */
final class SchemaConventionTest extends TestCase
{
    public function test_minor_and_milli_columns_are_bigint(): void
    {
        $found = 0;
        foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $file) {
            $sql = (string) preg_replace('/--[^\n]*/', '', (string) file_get_contents($file));
            preg_match_all('/`?\b([a-z_]+_(?:minor|milli))\b`?\s+([A-Za-z]+)/i', $sql, $m, PREG_SET_ORDER);
            foreach ($m as [, $col, $type]) {
                $found++;
                $this->assertSame('BIGINT', strtoupper($type), basename($file) . ": $col must be BIGINT");
            }
        }
        $this->assertGreaterThan(0, $found);
    }

    public function test_login_attempts_timestamp_is_bigint(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/001_base.sql');
        $this->assertMatchesRegularExpression('/login_attempts[^;]*\bat BIGINT\b/s', $sql);
    }
}
