<?php
declare(strict_types=1);

namespace App\Money;

/**
 * The one reader of typed decimal numbers (amounts, quantities), string arithmetic only, never
 * floats. AmountParser and QuantityParser differ only in the number of decimals and digits.
 *
 * Language rules (de/pl: "," decimal, "." group; en: "." decimal, "," group):
 *  - at most $maxFrac decimals after the language's decimal separator;
 *  - a group separator needs proper groups (1-3 digits, then groups of exactly 3);
 *  - the language's own group separator alone in proper groups is a thousands separator
 *    ("1.234" de = 1234, "1,234" en = 1234);
 *  - the foreign separator alone, once, with 1..$maxFrac digits after it and not in proper
 *    grouping, is the decimal point ("450.50" de = 450,50; "450,50" en = 450.50);
 *  - spaces (also NBSP, narrow NBSP, thin space) are only valid as proper thousands grouping;
 *    no sign, no letters, no NUL.
 *
 * @internal used by AmountParser and QuantityParser
 */
final class DecimalInput
{
    private const SEPARATORS = ['de' => [',', '.'], 'pl' => [',', '.'], 'en' => ['.', ',']];

    /**
     * @return string the digits of the value scaled by 10^$maxFrac (no leading zeros, '0' for zero)
     * @throws \InvalidArgumentException for anything it cannot read unambiguously
     */
    public static function scaled(string $input, string $lang, int $maxFrac, int $maxIntDigits): string
    {
        [$dec, $grp] = self::SEPARATORS[$lang] ?? throw new \InvalidArgumentException("Unsupported language: {$lang}");
        if (str_contains($input, "\0")) {
            throw self::bad($input);
        }
        $s = trim($input, " \t\n\r\u{00A0}\u{202F}\u{2009}");
        // Internal spaces are only valid as proper thousands grouping (1-3 digits, then groups of 3).
        if (preg_match('/[ \x{00A0}\x{202F}\x{2009}]/u', $s) === 1) {
            if (preg_match('/\A[0-9]{1,3}(?:[ \x{00A0}\x{202F}\x{2009}][0-9]{3})+(?:[.,][0-9]*)?\z/u', $s) !== 1) {
                throw self::bad($input);
            }
            $s = (string) preg_replace('/[ \x{00A0}\x{202F}\x{2009}]/u', '', $s);
        }
        if ($s === '' || preg_match('/\A[0-9.,]+\z/', $s) !== 1 || preg_match('/\A[0-9]/', $s) !== 1) {
            throw self::bad($input);
        }
        $hasDec = str_contains($s, $dec);
        $hasGrp = str_contains($s, $grp);
        $int = $s;
        $frac = '';

        if ($hasDec && $hasGrp) {
            // 1.234,56 (de) / 1,234.56 (en): group separators only before the single decimal one.
            if (substr_count($s, $dec) !== 1) {
                throw self::bad($input);
            }
            [$int, $frac] = explode($dec, $s);
            if (!self::properGroups($int, $grp)) {
                throw self::bad($input);
            }
            $int = str_replace($grp, '', $int);
        } elseif ($hasDec) {
            if (substr_count($s, $dec) !== 1) {
                throw self::bad($input);
            }
            [$int, $frac] = explode($dec, $s);
        } elseif ($hasGrp) {
            if (self::properGroups($s, $grp)) {
                $int = str_replace($grp, '', $s);
            } elseif (substr_count($s, $grp) === 1) {
                [$int, $frac] = explode($grp, $s); // foreign decimal point
            } else {
                throw self::bad($input);
            }
        }

        if (preg_match('/\A[0-9]+\z/', $int) !== 1 || ($frac !== '' && preg_match('/\A[0-9]{1,' . $maxFrac . '}\z/', $frac) !== 1)
            || ($frac === '' && ($hasDec || ($hasGrp && !self::properGroups($s, $grp))))) {
            throw self::bad($input);
        }
        $int = ltrim($int, '0');
        if (strlen($int) > $maxIntDigits) {
            throw self::bad($input);
        }
        $digits = ltrim(($int === '' ? '0' : $int) . str_pad($frac, $maxFrac, '0'), '0');
        return $digits === '' ? '0' : $digits;
    }

    private static function properGroups(string $s, string $sep): bool
    {
        $q = preg_quote($sep, '/');
        return preg_match('/\A[0-9]{1,3}(' . $q . '[0-9]{3})*\z/', $s) === 1;
    }

    private static function bad(string $input): \InvalidArgumentException
    {
        return new \InvalidArgumentException('Not a valid number: ' . mb_substr($input, 0, 40));
    }
}
