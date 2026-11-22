<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Validates a user-supplied "redirect back" target. Only local absolute paths are accepted:
 * a single leading '/', no '//' anywhere (protocol-relative or ambiguous), no backslash
 * (browsers treat '/\host' like '//host'), no whitespace or control characters (header
 * injection) and only URL characters. Everything else falls back to '/'.
 */
final class BackPath
{
    public const DEFAULT = '/';
    private const MAX_LENGTH = 2000;

    public static function sanitize(?string $value): string
    {
        if ($value === null || $value === '' || strlen($value) > self::MAX_LENGTH) {
            return self::DEFAULT;
        }
        if (preg_match('~\A/[A-Za-z0-9\-._\~!$&\'()*+,;=:@%/?]*\z~', $value) !== 1 || str_contains($value, '//')) {
            return self::DEFAULT;
        }
        return $value;
    }
}
