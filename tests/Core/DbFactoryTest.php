<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\DbFactory;
use PHPUnit\Framework\TestCase;

final class DbFactoryTest extends TestCase
{
    public function test_sqlite_from_config(): void
    {
        $path = sys_get_temp_dir() . '/faktura_' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $db = DbFactory::fromConfig(['db' => ['driver' => 'sqlite', 'sqlite' => $path]]);
            $this->assertSame('sqlite', $db->driver());
            $this->assertSame(1, (int) $db->one('SELECT 1 AS x')['x']);
            $this->assertFileExists($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_mysql_options(): void
    {
        $o = DbFactory::mysqlOptions();
        $this->assertTrue($o[\PDO::MYSQL_ATTR_FOUND_ROWS]);
        $this->assertFalse($o[\PDO::ATTR_EMULATE_PREPARES]);
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $o[\PDO::ATTR_ERRMODE]);
        $this->assertSame(
            'mysql:host=db.local;port=3307;dbname=fak;charset=utf8mb4',
            DbFactory::mysqlDsn(['host' => 'db.local', 'port' => 3307, 'name' => 'fak'])
        );
    }

    public function test_unknown_driver(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DbFactory::fromConfig(['db' => ['driver' => 'pgsql']]);
    }
}
