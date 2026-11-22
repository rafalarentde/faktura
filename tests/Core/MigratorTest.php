<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Db;
use App\Core\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private function rawDb(): Db
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        return new Db($pdo, 'sqlite');
    }

    public function test_run_applies_each_file_once(): void
    {
        $db = $this->rawDb();
        $m = new Migrator($db, __DIR__ . '/../../migrations');
        $first = $m->run();
        $this->assertGreaterThanOrEqual(1, $first);
        $this->assertSame(0, $m->run());
        $this->assertSame($first, (int) $db->one('SELECT COUNT(*) AS c FROM schema_migrations')['c']);
    }

    public function test_base_tables_exist(): void
    {
        $db = \TestDb::fresh();
        foreach (['users', 'backup_codes', 'companies', 'company_users', 'login_attempts', 'audit_log'] as $t) {
            $this->assertNotNull(
                $db->driver() === 'mysql'
                    ? $db->one('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t])
                    : $db->one("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$t]),
                "Table $t missing"
            );
        }
    }

    public function test_company_defaults(): void
    {
        $db = \TestDb::fresh();
        $db->exec("INSERT INTO companies (name, created_at) VALUES ('Acme', '2026-01-01 00:00:00')");
        $c = $db->one('SELECT * FROM companies');
        $this->assertSame(7, (int) $c['dunning_d0']);
        $this->assertSame(21, (int) $c['dunning_d1']);
        $this->assertSame(35, (int) $c['dunning_d2']);
        $this->assertSame(49, (int) $c['dunning_d3']);
        $this->assertNull($c['dunning_base_rate_bp']);
        $this->assertSame(900, (int) $c['dunning_b2b_surcharge_bp']);
        $this->assertSame(500, (int) $c['dunning_b2c_surcharge_bp']);
        $this->assertSame(4000, (int) $c['dunning_flat_minor']);
        $this->assertSame(0, (int) $c['dunning_fee_minor']);
        $this->assertSame('active', $c['access_status']);
        $this->assertNull($c['access_until']);
        $this->assertSame('free', $c['plan']);
    }

    public function test_user_defaults(): void
    {
        $db = \TestDb::fresh();
        $db->exec("INSERT INTO users (email, password_hash, created_at) VALUES ('a@b.de', 'x', '2026-01-01 00:00:00')");
        $u = $db->one('SELECT * FROM users');
        $this->assertSame(0, (int) $u['totp_enabled']);
        $this->assertSame('de', $u['ui_lang']);
        $this->assertSame(0, (int) $u['is_admin']);
        $this->assertNull($u['totp_secret_enc']);
    }

    private function tmpDir(): string
    {
        $d = sys_get_temp_dir() . '/mig_' . bin2hex(random_bytes(4));
        mkdir($d);
        return $d;
    }

    private function tableExists(Db $db, string $t): bool
    {
        return $db->one("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$t]) !== null;
    }

    public function test_unreadable_file_throws_and_is_not_recorded(): void
    {
        $d = $this->tmpDir();
        symlink($d . '/missing-target', $d . '/001_x.sql'); // dangling link: unreadable
        $db = $this->rawDb();
        try {
            (new Migrator($db, $d))->run();
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('001_x.sql', $e->getMessage());
        }
        $this->assertSame([], $db->all('SELECT * FROM schema_migrations'));
    }

    public function test_comment_only_file_throws(): void
    {
        $d = $this->tmpDir();
        file_put_contents($d . '/001_x.sql', "-- nothing here\n\n");
        $db = $this->rawDb();
        $this->expectException(\RuntimeException::class);
        try {
            (new Migrator($db, $d))->run();
        } finally {
            $this->assertSame([], $db->all('SELECT * FROM schema_migrations'));
        }
    }

    public function test_failed_file_leaves_nothing_and_can_be_fixed(): void
    {
        $d = $this->tmpDir();
        $f = $d . '/001_x.sql';
        file_put_contents($f, "CREATE TABLE a (id INTEGER);\nCREATE TABLE b (id INTEGER, ;\n");
        $db = $this->rawDb();
        $m = new Migrator($db, $d);
        try {
            $m->run();
            $this->fail('Exception expected');
        } catch (\PDOException $e) {
            $this->assertTrue(true);
        }
        $this->assertFalse($this->tableExists($db, 'a'));
        $this->assertSame([], $db->all('SELECT * FROM schema_migrations'));

        file_put_contents($f, "CREATE TABLE a (id INTEGER);\nCREATE TABLE b (id INTEGER);\n");
        $this->assertSame(1, $m->run());
        $this->assertTrue($this->tableExists($db, 'a'));
        $this->assertTrue($this->tableExists($db, 'b'));
    }

    public function test_users_have_totp_last_step_column(): void
    {
        $db = \TestDb::fresh();
        $db->exec("INSERT INTO users (email, password_hash) VALUES ('a@b.pl', 'x')");
        $this->assertArrayHasKey('totp_last_step', $db->one('SELECT * FROM users'));
        $this->assertNull($db->one('SELECT totp_last_step FROM users')['totp_last_step']);
    }

    public function test_add_column_is_skipped_when_column_exists(): void
    {
        $d = $this->tmpDir();
        file_put_contents($d . '/001_a.sql', "CREATE TABLE t (id INTEGER, extra INTEGER NULL);\n");
        file_put_contents($d . '/002_b.sql', "ALTER TABLE t ADD COLUMN extra INTEGER NULL;\nALTER TABLE t ADD COLUMN other INTEGER NULL;\n");
        $db = $this->rawDb();
        $this->assertSame(2, (new Migrator($db, $d))->run());
        $db->exec('INSERT INTO t (id, extra, other) VALUES (1, 2, 3)');
        $this->assertSame(3, (int) $db->one('SELECT other FROM t')['other']);
    }
}
