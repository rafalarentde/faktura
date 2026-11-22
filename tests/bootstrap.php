<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// No App\Core\ErrorHandling::install() here: PHPUnit 11 skips its own error handler when one is
// already registered, which would hide warnings/notices/deprecations from the run. phpunit.xml
// instead fails the suite on any warning, notice or deprecation.

use App\Core\Db;
use App\Core\Migrator;

final class TestDb
{
    /** Fresh in-memory SQLite database with all migrations applied. */
    public static function fresh(string $pdoClass = PDO::class): Db
    {
        if (getenv('FAKTURA_TEST_MYSQL') !== false && getenv('FAKTURA_TEST_MYSQL') !== '') {
            return self::freshMysql($pdoClass);
        }
        $pdo = new $pdoClass('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db = new Db($pdo, 'sqlite');
        (new Migrator($db, __DIR__ . '/../migrations'))->run();
        return $db;
    }

    /**
     * Opt-in real-database run (FAKTURA_TEST_MYSQL="host|user|pass"): every call gets a new, empty
     * database with all migrations applied; databases are dropped at shutdown.
     */
    private static function freshMysql(string $pdoClass): Db
    {
        [$host, $user, $pass] = array_pad(explode('|', (string) getenv('FAKTURA_TEST_MYSQL')), 3, '');
        $admin = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $name = 'ft_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        register_shutdown_function(static function () use ($host, $user, $pass, $name): void {
            try {
                (new PDO("mysql:host=$host", $user, $pass))->exec("DROP DATABASE IF EXISTS `$name`");
            } catch (\Throwable) {
            }
        });
        $pdo = new $pdoClass(\App\Core\DbFactory::mysqlDsn(['host' => $host, 'name' => $name]), $user, $pass, \App\Core\DbFactory::mysqlOptions());
        $db = new Db($pdo, 'mysql');
        (new Migrator($db, __DIR__ . '/../migrations'))->run();
        return $db;
    }
}
