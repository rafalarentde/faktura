<?php
declare(strict_types=1);

namespace App\Domain;

/** IBAN normalisation and validation (country length + ISO 7064 mod 97-10), string arithmetic only. */
final class Iban
{
    /** Fixed IBAN length per country (SEPA/EEA countries and a few common others). */
    private const LENGTHS = [
        'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CH' => 21, 'CY' => 28, 'CZ' => 24, 'DE' => 22,
        'DK' => 18, 'EE' => 20, 'ES' => 24, 'FI' => 18, 'FR' => 27, 'GB' => 22, 'GR' => 27, 'HR' => 21,
        'HU' => 28, 'IE' => 22, 'IS' => 26, 'IT' => 27, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21,
        'MC' => 27, 'MT' => 31, 'NL' => 18, 'NO' => 15, 'PL' => 28, 'PT' => 25, 'RO' => 24, 'SE' => 24,
        'SI' => 19, 'SK' => 24, 'SM' => 27, 'VA' => 22,
    ];

    /** Spaces removed, upper case. */
    public static function normalize(string $raw): string
    {
        return strtoupper(preg_replace('/[\s\x{00A0}]+/u', '', $raw) ?? '');
    }

    /** Valid when the normalised form has the right shape and length and a correct check number. */
    public static function isValid(string $raw): bool
    {
        $iban = self::normalize($raw);
        if (preg_match('/\A[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}\z/', $iban) !== 1) {
            return false;
        }
        $expected = self::LENGTHS[substr($iban, 0, 2)] ?? null;
        if ($expected !== null && strlen($iban) !== $expected) {
            return false;
        }
        return self::mod97(substr($iban, 4) . substr($iban, 0, 4)) === 1;
    }

    /** Remainder of the digit string (letters A=10 .. Z=35) modulo 97, digit by digit. */
    private static function mod97(string $s): int
    {
        $rem = 0;
        foreach (str_split($s) as $ch) {
            $digits = ctype_digit($ch) ? $ch : (string) (ord($ch) - 55);
            foreach (str_split($digits) as $d) {
                $rem = ($rem * 10 + (int) $d) % 97;
            }
        }
        return $rem;
    }
}
