<?php
declare(strict_types=1);

// Retention for the audit log: removes auth.* rows (logins, 2FA, logouts) older than 90 days.
// Business events (document.*, settings.*, admin.* ...) are kept.
// Usage: php bin/purge-audit.php [days]      (days >= 1, default 90)
// Cron (daily, 03:30):  30 3 * * * php /path/to/faktura/bin/purge-audit.php >> /var/log/faktura-purge.log 2>&1

use App\Core\Audit;
use App\Core\DbFactory;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../vendor/autoload.php';
\App\Core\ErrorHandling::install(true);

$days = Audit::AUTH_RETENTION_DAYS;
if (isset($argv[1])) {
    if (preg_match('/\A[1-9][0-9]{0,4}\z/', $argv[1]) !== 1) {
        fwrite(STDERR, "Usage: php bin/purge-audit.php [days]\n");
        exit(2);
    }
    $days = (int) $argv[1];
}

$config = require (getenv('FAKTURA_CONFIG') ?: __DIR__ . '/../config/config.php');
$removed = (new Audit(DbFactory::fromConfig($config)))->purgeAuth($days);
fwrite(STDOUT, gmdate('c') . " purged $removed auth audit row(s) older than $days days\n");
