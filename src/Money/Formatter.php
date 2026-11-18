<?php
declare(strict_types=1);

namespace App\Money;

/** Locale-aware formatting of money, dates and quantities (de, pl, en). */
final class Formatter
{
    private const LOCALES = ['de' => 'de_DE', 'pl' => 'pl_PL', 'en' => 'en_GB'];
    private const DATE_FORMATS = ['de' => 'd.m.Y', 'pl' => 'd.m.Y', 'en' => 'd/m/Y'];
    // Largest minor amount a double represents exactly (NumberFormatter takes int|float).
    private const MAX_EXACT = 9007199254740992;

    public function money(Money $money, string $lang): string
    {
        $minor = $money->minor();
        if (abs($minor) > self::MAX_EXACT) {
            throw new \OverflowException('Amount too large to format exactly');
        }
        $fmt = new \NumberFormatter(self::locale($lang), \NumberFormatter::CURRENCY);
        $out = $fmt->formatCurrency($minor / 100, $money->currency());
        if ($out === false) {
            throw new \RuntimeException('Money formatting failed: ' . $fmt->getErrorMessage());
        }
        return $out;
    }

    public function date(\DateTimeInterface $date, string $lang): string
    {
        self::locale($lang);
        return $date->format(self::DATE_FORMATS[$lang]);
    }

    /** Quantity in thousandths, up to 3 decimals, no trailing zeros. */
    public function decimal(int $qtyMilli, string $lang): string
    {
        $fmt = new \NumberFormatter(self::locale($lang), \NumberFormatter::DECIMAL);
        $fmt->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $fmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 3);
        $out = $fmt->format($qtyMilli / 1000);
        if ($out === false) {
            throw new \RuntimeException('Decimal formatting failed: ' . $fmt->getErrorMessage());
        }
        return $out;
    }

    private static function locale(string $lang): string
    {
        return self::LOCALES[$lang] ?? throw new \InvalidArgumentException("Unsupported language: {$lang}");
    }
}
