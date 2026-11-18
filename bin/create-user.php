<?php
declare(strict_types=1);

// Creates a user: php bin/create-user.php email@example.com [--admin]
// The password is read from STDIN (prompted without echo on a terminal), never from argv,
// so it does not end up in shell history or the process list. The user sets up 2FA at first login.

use App\Auth\UserService;
use App\Core\DbFactory;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../vendor/autoload.php';

$args = array_slice($argv, 1);
$isAdmin = in_array('--admin', $args, true);
$emails = array_values(array_filter($args, fn (string $a): bool => !str_starts_with($a, '--')));
if (count($emails) !== 1) {
    fwrite(STDERR, "Usage: php bin/create-user.php email@example.com [--admin]  (password from STDIN)\n");
    exit(2);
}

$tty = function_exists('posix_isatty') && posix_isatty(STDIN);
if ($tty) {
    fwrite(STDOUT, 'Password (min ' . UserService::MIN_PASSWORD_LENGTH . ' characters): ');
    shell_exec('stty -echo');
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($tty) {
    shell_exec('stty echo');
    fwrite(STDOUT, "\n");
}

$config = require (getenv('FAKTURA_CONFIG') ?: __DIR__ . '/../config/config.php');
try {
    $id = (new UserService(DbFactory::fromConfig($config)))->create($emails[0], $password, $isAdmin);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, "Created user #$id" . ($isAdmin ? ' (admin)' : '') . ".\n");
