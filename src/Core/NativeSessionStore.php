<?php
declare(strict_types=1);

namespace App\Core;

/** SessionStore over PHP's native session handling ($_SESSION). */
final class NativeSessionStore implements SessionStore
{
    private string $name = 'PHPSESSID';

    /** @var array<string, mixed> */
    private array $cookieParams = [];

    public function start(string $name, array $cookieParams, int $maxLifetime): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $this->name = $name;
        $this->cookieParams = $cookieParams;
        session_start([
            'name' => $name,
            'cookie_lifetime' => $cookieParams['lifetime'],
            'cookie_path' => $cookieParams['path'],
            'cookie_domain' => $cookieParams['domain'],
            'cookie_secure' => $cookieParams['secure'],
            'cookie_httponly' => $cookieParams['httponly'],
            'cookie_samesite' => $cookieParams['samesite'],
            'use_strict_mode' => true,
            'use_only_cookies' => true,
            'use_cookies' => true,
            'use_trans_sid' => false,
            'gc_maxlifetime' => $maxLifetime,
            'cache_limiter' => '', // App sets Cache-Control itself
        ]);
    }

    public function id(): string
    {
        return (string) session_id();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function clear(): void
    {
        $_SESSION = [];
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if (!headers_sent()) {
            $p = $this->cookieParams;
            setcookie($this->name, '', [
                'expires' => 1,
                'path' => $p['path'] ?? '/',
                'domain' => $p['domain'] ?? '',
                'secure' => $p['secure'] ?? false,
                'httponly' => true,
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
    }
}
