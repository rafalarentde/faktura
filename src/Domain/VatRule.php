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
     * @param array{country?: string, is_company?: bool, vat_id?: ?string, vies?: ?ViesStatus} $buyer
     * @throws \InvalidArgumentException when the country is not a 2-letter code
     */
    public static function decide(array $buyer): VatDecision
    {
        $country = strtoupper(trim((string) ($buyer['country'] ?? '')));
        if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
            throw new \InvalidArgumentException('Buyer country must be a 2-letter ISO code.');
        }

        if ($country === self::SELLER_COUNTRY) {
            return new VatDecision(false, false, null, 'domestic');
        }
        if (!Countries::isEu($country)) {
            return new VatDecision(false, false, null, 'non_eu');
        }
        if (($buyer['is_company'] ?? false) !== true) {
            return new VatDecision(false, false, null, 'private_customer');
        }
        if (trim((string) ($buyer['vat_id'] ?? '')) === '') {
            // A Valid status is never trusted without a VAT id.
            return new VatDecision(false, false, null, 'no_vat_id');
        }
        if (($buyer['vies'] ?? null) === ViesStatus::Valid) {
            return new VatDecision(true, false, 'invoice.reverse_charge_note', 'reverse_charge');
        }
        return new VatDecision(false, true, null, 'vies_not_confirmed');
    }
}
