<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * The fixed list of units an item can have. Items store the KEY; the label comes from the
 * translation key `unit.<key>` (UI now, PDFs later).
 */
final class Units
{
    public const KEYS = ['hour', 'day', 'month', 'piece', 'flat', 'km', 'kg'];

    public static function isValid(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    public static function labelKey(string $key): string
    {
        return 'unit.' . $key;
    }
}
