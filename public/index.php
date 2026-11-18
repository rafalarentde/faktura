<?php
declare(strict_types=1);

// Front controller. Migrations are NOT run here; use bin/migrate.php.

use App\Core\App;
use App\Core\DbFactory;
use App\Core\Request;

require __DIR__ . '/../vendor/autoload.php';

try {
    $configPath = getenv('FAKTURA_CONFIG') ?: __DIR__ . '/../config/config.php';
    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException("Config file $configPath must return an array.");
    }
    $app = new App($config, DbFactory::fromConfig($config));
} catch (Throwable $e) {
    // Bootstrap failed (config, database connection): no details to the client.
    error_log('faktura bootstrap: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Service unavailable.';
    exit;
}

$app->handle(Request::fromGlobals())->send();
