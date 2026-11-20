<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Per-session synchronizer token, sent as the `_csrf` form field on every POST (or, for scripts,
 * as the X-CSRF-Token request header; App accepts either).
 */
final class Csrf
{
    public const SESSION_KEY = '_csrf';
    public const FIELD = '_csrf';
    public const HEADER = 'X-CSRF-Token';

    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $t = $this->session->get(self::SESSION_KEY);
        if (!is_string($t) || $t === '') {
            $t = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $t);
        }
        return $t;
    }

    public function check(string $token): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);
        return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }

    /** New token, e.g. after login. */
    public function rotate(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }
}
