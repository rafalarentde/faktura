<?php
declare(strict_types=1);

// Router for the PHP built-in server (local development only; production uses public/.htaccess):
//   php -S 127.0.0.1:8000 -t public bin/dev-router.php
if (PHP_SAPI !== 'cli-server') {
    exit(1);
}
$path = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = realpath(__DIR__ . '/../public' . $path);
$public = realpath(__DIR__ . '/../public');
// Serve existing static files (never dotfiles or PHP); everything else goes to the front controller.
if ($file !== false && $public !== false && str_starts_with($file, $public . DIRECTORY_SEPARATOR)
    && is_file($file) && !str_starts_with(basename($file), '.') && !str_ends_with($file, '.php')) {
    return false;
}
require __DIR__ . '/../public/index.php';
