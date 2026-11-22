<?php
declare(strict_types=1);

namespace App\Domain;

use App\Vies\ViesStatus;

/**
 * Decides whether an invoice is issued under the reverse-charge mechanism.
 *
 * ASSUMPTION: the seller is German (DE); the company's own country is not stored yet.
 * Reverse charge is applied ONLY for an EU company outside DE with a VAT id that VIES confirmed
 * as valid. If VIES is invalid, unavailable or was never checked, the result is "needs
 * confirmation" and never a silent reverse charge. Non-EU buyers fall back to German VAT; the
 * place-of-supply rules for them are NOT decided here (TODO: verify with the Steuerberater).
 */
final class VatRule
{
    private const SELLER_COUNTRY = 'DE';

    /**
     * Strict: `is_company` must be present and a bool; `vies` may be absent (= never checked) but
     * if present must be null or a ViesStatus; `vat_id` must be null/absent or a string. Database
     * rows go through buyerFromCustomerRow().
     * Country: trimmed, upper-cased, "EL" (Greece's VAT prefix) is mapped to GR, then it must be
     * a code from Countries (otherwise InvalidArgumentException). XI (Northern Ireland) is not
     * supported (TODO: verify with the Steuerberater).
     *
     * @param array<string, mixed> $buyer country, is_company, vat_id, vies
     * @throws \InvalidArgumentException
     */
    public static function decide(array $buyer): VatDecision
    {
        $rawCountry = $buyer['country'] ?? null;
        if (!is_string($rawCountry)) {
            throw new \InvalidArgumentException('Buyer country must be a string.');
        }
        $country = strtoupper(trim($rawCountry));
        if ($country === 'EL') {
            $country = 'GR';
        }
        if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1 || !Countries::isValid($country)) {
            throw new \InvalidArgumentException('Unsupported buyer country.');
        }
        if (!array_key_exists('is_company', $buyer) || !is_bool($buyer['is_company'])) {
            throw new \InvalidArgumentException('is_company must be a bool.');
        }
        $vatId = $buyer['vat_id'] ?? null;
        if ($vatId !== null && !is_string($vatId)) {
            throw new \InvalidArgumentException('vat_id must be null or a string.');
        }
        $vies = $buyer['vies'] ?? null;
        if ($vies !== null && !$vies instanceof ViesStatus) {
            throw new \InvalidArgumentException('vies must be null or a ViesStatus.');
        }

        if ($country === self::SELLER_COUNTRY) {
            return new VatDecision(false, false, null, 'domestic');
        }
        if (!Countries::isEu($country)) {
            return new VatDecision(false, false, null, 'non_eu');
        }
        if (!$buyer['is_company']) {
            return new VatDecision(false, false, null, 'private_customer');
        }
        if (trim((string) $vatId) === '') {
            // A Valid status is never trusted without a VAT id.
            return new VatDecision(false, false, null, 'no_vat_id');
        }
        if ($vies === ViesStatus::Valid) {
            return new VatDecision(true, false, 'invoice.reverse_charge_note', 'reverse_charge');
        }
        return new VatDecision(false, true, null, 'vies_not_confirmed');
    }

    /**
     * Maps a `customers` row (country, is_company 0/1, vat_id, vies_status null|valid|invalid|unavailable)
     * to the strict buyer array for decide().
     *
     * @param array<string, mixed> $row
     * @return array{country: mixed, is_company: bool, vat_id: ?string, vies: ?ViesStatus}
     * @throws \InvalidArgumentException
     */
    public static function buyerFromCustomerRow(array $row): array
    {
        $company = $row['is_company'] ?? null;
        if ($company === 1 || $company === '1' || $company === true) {
            $isCompany = true;
        } elseif ($company === 0 || $company === '0' || $company === false) {
            $isCompany = false;
        } else {
            throw new \InvalidArgumentException('is_company must be 0 or 1.');
        }
        $status = $row['vies_status'] ?? null;
        if ($status === null) {
            $vies = null;
        } elseif (is_string($status) && ($vies = ViesStatus::tryFrom($status)) !== null) {
            // mapped
        } else {
            throw new \InvalidArgumentException('Unknown vies_status.');
        }
        $vatId = $row['vat_id'] ?? null;
        if ($vatId !== null && !is_string($vatId)) {
            throw new \InvalidArgumentException('vat_id must be null or a string.');
        }
        return ['country' => $row['country'] ?? null, 'is_company' => $isCompany, 'vat_id' => $vatId, 'vies' => $vies];
    }
}
