<?php
declare(strict_types=1);

// Applies pending migrations: php bin/migrate.php (locally or via hosting cron).

use App\Core\DbFactory;
use App\Core\Migrator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../vendor/autoload.php';
\App\Core\ErrorHandling::install(true);

$config = require (getenv('FAKTURA_CONFIG') ?: __DIR__ . '/../config/config.php');
$n = (new Migrator(DbFactory::fromConfig($config), __DIR__ . '/../migrations'))->run();
fwrite(STDOUT, "Applied $n migration(s).\n");
