<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Turns PHP warnings/notices into ErrorException (so App::handle() answers with a 500
 * instead of carrying on with bad data) and keeps error details off the page unless debug.
 * Deprecations are left to the normal log so a PHP/vendor upgrade cannot break requests.
 * Errors silenced with @ are not converted.
 */
final class ErrorHandling
{
    private static ?\Closure $handler = null;

    /** Idempotent: pushes the handler only if it is not already the active one. */
    public static function install(bool $debug): void
    {
        // Report everything (hosts often ship a reduced level); @ still silences a single call.
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        self::$handler ??= static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $severity) === 0 || ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
                return false; // suppressed (@) or deprecation: PHP's default handling
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        };
        if (!self::isActive()) {
            set_error_handler(self::$handler);
        }
    }

    /** Pops the handler if it is the active one, restoring the previous handler (used by tests). */
    public static function uninstall(): void
    {
        if (self::isActive()) {
            restore_error_handler();
        }
    }

    private static function isActive(): bool
    {
        $current = set_error_handler(null);
        restore_error_handler();
        return self::$handler !== null && $current === self::$handler;
    }
}
