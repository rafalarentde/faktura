<?php
declare(strict_types=1);

namespace App\Domain;

/** The one list of countries a customer can have (ISO 3166-1 alpha-2, upper case). */
final class Countries
{
    /** EU member states. */
    public const EU = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE',
        'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    /** Common non-EU countries. */
    public const OTHER = ['CH', 'GB', 'NO', 'IS', 'LI', 'US', 'CA', 'UA', 'TR', 'AU', 'JP', 'CN'];

    /** @return list<string> all allowed codes, Germany and Poland first, the rest sorted */
    public static function all(): array
    {
        $rest = array_values(array_diff([...self::EU, ...self::OTHER], ['DE', 'PL']));
        sort($rest, SORT_STRING);
        return ['DE', 'PL', ...$rest];
    }

    public static function isValid(string $code): bool
    {
        return in_array($code, self::EU, true) || in_array($code, self::OTHER, true);
    }

    public static function isEu(string $code): bool
    {
        return in_array($code, self::EU, true);
    }
}
