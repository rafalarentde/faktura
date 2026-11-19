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
    public static function fresh(): Db
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db = new Db($pdo, 'sqlite');
        (new Migrator($db, __DIR__ . '/../migrations'))->run();
        return $db;
    }
}
