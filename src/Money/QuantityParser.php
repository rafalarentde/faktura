<?php
declare(strict_types=1);

namespace App\Money;

/**
 * Turns a typed quantity into thousandths (qty_milli: "2,5" de/pl and "2.5" en = 2500) with string
 * arithmetic only. Same separators and strictness as AmountParser (see DecimalInput), but up to 3
 * decimals; the value must be > 0 and at most 999,999.999.
 *
 * @throws \InvalidArgumentException for anything it cannot read unambiguously or out of range
 */
final class QuantityParser
{
    private const MAX_INT_DIGITS = 6;

    public static function parse(string $input, string $lang): int
    {
        $milli = (int) DecimalInput::scaled($input, $lang, 3, self::MAX_INT_DIGITS);
        if ($milli <= 0) {
            throw new \InvalidArgumentException('A quantity must be greater than zero.');
        }
        return $milli;
    }
}
