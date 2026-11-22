<?php
declare(strict_types=1);

namespace App\Domain;

/** VAT identification numbers: normalisation and the country prefix rules. */
final class VatId
{
    /** Prefix of the VAT number for an ISO country code (Greece: ISO GR, VAT prefix EL). */
    public static function prefix(string $country): string
    {
        $country = strtoupper($country);
        return $country === 'GR' ? 'EL' : $country;
    }

    /**
     * Canonical stored form: separators (spaces, dots, dashes) removed, upper case, and exactly
     * one country prefix in front (added when missing, duplicates dropped; for Greece a typed GR
     * becomes EL). Returns null when nothing but the prefix is left. Shape is not checked here.
     */
    public static function normalize(string $country, string $raw): ?string
    {
        $v = strtoupper(preg_replace('/[\s.\-]+/u', '', $raw) ?? '');
        $prefix = self::prefix($country);
        $alt = strtoupper($country) === 'GR' ? 'GR' : $prefix;
        while ($v !== '' && (str_starts_with($v, $prefix) || str_starts_with($v, $alt))) {
            $v = substr($v, 2);
        }
        return $v === '' ? null : $prefix . $v;
    }

    /** The part after the country prefix (what VIES wants as the number). */
    public static function number(string $country, string $vatId): string
    {
        $prefix = self::prefix($country);
        return str_starts_with($vatId, $prefix) ? substr($vatId, strlen($prefix)) : $vatId;
    }
}
