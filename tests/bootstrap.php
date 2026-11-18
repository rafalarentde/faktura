<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

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
