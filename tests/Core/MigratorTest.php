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
                $db->one("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$t]),
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
}
