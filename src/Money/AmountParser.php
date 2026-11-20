<?php
declare(strict_types=1);

namespace App\Money;

/**
 * Turns a typed amount into minor units (cents/grosze) with string arithmetic only, never floats.
 *
 * Language rules (de/pl: "," decimal, "." group; en: "." decimal, "," group), see DecimalInput:
 *  - at most 2 decimals; "1,234" in de/pl is three decimals and is rejected (never 1234);
 *  - a group separator needs proper groups (1-3 digits, then groups of exactly 3);
 *  - the language's own group separator alone is a thousands separator ("1.234" de = 1234,
 *    "1,234" en = 1234);
 *  - the foreign separator alone, once, with 1-2 digits after it, is the decimal point
 *    ("450.50" de = 450,50; "450,50" en = 450.50);
 *  - spaces (also NBSP, narrow NBSP, thin space) are ignored; no sign, no letters;
 *  - the maximum is 999,999,999.99.
 *
 * @throws \InvalidArgumentException for anything it cannot read unambiguously
 */
final class AmountParser
{
    private const MAX_INT_DIGITS = 9;

    public static function parse(string $input, string $lang): int
    {
        return (int) DecimalInput::scaled($input, $lang, 2, self::MAX_INT_DIGITS);
    }
}
