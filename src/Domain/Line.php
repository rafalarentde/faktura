<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * One invoice line as the calculation sees it. Pure value object; the net amount is computed in
 * exactly one place, Totals::lineNet().
 */
final class Line
{
    public const VAT_RATES_BP = [1900, 700, 0];

    /**
     * @param int $qtyMilli   quantity x 1000 (2.5 = 2500), > 0
     * @param int $unitMinor  unit price in minor units, >= 0
     * @param int $discountBp line discount in basis points, 0..10000
     * @param int $vatBp      1900, 700 or 0
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public readonly int $qtyMilli,
        public readonly int $unitMinor,
        public readonly int $discountBp,
        public readonly int $vatBp,
    ) {
        if ($qtyMilli <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }
        if ($unitMinor < 0) {
            throw new \InvalidArgumentException('Unit price must not be negative.');
        }
        if ($discountBp < 0 || $discountBp > 10000) {
            throw new \InvalidArgumentException('Discount must be between 0 and 10000 bp.');
        }
        if (!in_array($vatBp, self::VAT_RATES_BP, true)) {
            throw new \InvalidArgumentException("Unsupported VAT rate: {$vatBp} bp.");
        }
    }
}
