<?php
declare(strict_types=1);

namespace App\Domain;

use App\Money\Money;
use App\Money\Rounding;

/**
 * Invoice totals. All arithmetic is exact integer arithmetic (no floats), rounding half-up.
 *
 * - Line net: ONE rounding, round(unit x qty x (10000 - discount) / 10^7).
 * - VAT: once per rate from the SUM of the line nets at that rate (never per line).
 * - Reverse charge: every line counts as 0 % VAT, `vat` is empty, nets are reported under key 0.
 *   (Under reverse charge nets are therefore reported under key 0; a 0 % line outside reverse
 *   charge is a normal rate that occurs, so vat[0] = 0 and nets[0] are present.)
 * - VAT in EUR (German VAT law wants the VAT amount in EUR on foreign-currency invoices) is
 *   isolated in vatToEur(). TODO: flagged "do weryfikacji" with the Steuerberater.
 */
final class Totals
{
    private const CURRENCIES = ['EUR', 'PLN', 'USD'];
    private const RATE_SCALE = 1_000_000;
    private const NET_DIVISOR = 10_000_000; // 1000 (qty milli) x 10000 (bp)

    /**
     * @param array<int, int> $vat  vatBp => VAT minor, only occurring rates, descending; [] under reverse charge
     * @param array<int, int> $nets vatBp => net minor (all under key 0 for reverse charge)
     */
    private function __construct(
        public readonly int $netMinor,
        public readonly array $vat,
        public readonly array $nets,
        public readonly int $vatMinor,
        public readonly int $grossMinor,
        public readonly ?int $vatEurMinor,
    ) {
    }

    /**
     * @param list<Line> $lines
     * @param ?string $eurRate units of the invoice currency per ONE euro, e.g. "4.2650"
     * @throws \InvalidArgumentException|\OverflowException
     */
    public static function compute(array $lines, bool $reverseCharge, string $currency, ?string $eurRate = null): self
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('At least one line is required.');
        }
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new \InvalidArgumentException("Unsupported currency: {$currency}");
        }
        $rateScaled = $eurRate === null ? null : self::parseRate($eurRate);

        $nets = [];
        $netMinor = 0;
        foreach ($lines as $line) {
            if (!$line instanceof Line) {
                throw new \InvalidArgumentException('Lines must be Line instances.');
            }
            $key = $reverseCharge ? 0 : $line->vatBp;
            $net = self::lineNet($line);
            $nets[$key] = self::checked(($nets[$key] ?? 0) + $net);
            $netMinor = self::checked($netMinor + $net);
        }
        krsort($nets, SORT_NUMERIC);

        $vat = [];
        $vatMinor = 0;
        if (!$reverseCharge) {
            foreach ($nets as $bp => $sum) {
                $vat[$bp] = Money::of($sum, $currency)->percentBp($bp)->minor();
                $vatMinor = self::checked($vatMinor + $vat[$bp]);
            }
        }
        $grossMinor = self::checked($netMinor + $vatMinor);

        if ($currency === 'EUR') {
            if ($rateScaled !== null && $rateScaled !== self::RATE_SCALE) {
                throw new \InvalidArgumentException('An EUR invoice needs no exchange rate.');
            }
            $vatEur = $vatMinor;
        } elseif ($rateScaled === null) {
            if (!$reverseCharge) {
                throw new \InvalidArgumentException('An exchange rate is required for foreign-currency invoices.');
            }
            $vatEur = 0;
        } else {
            $vatEur = self::vatToEur($vatMinor, $rateScaled);
        }

        return new self($netMinor, $vat, $nets, $vatMinor, $grossMinor, $vatEur);
    }

    /**
     * round_half_up(unit x qty x (10000 - discount) / 10^7), exact, one rounding.
     *
     * With D = 10^7 write unit = u1*D + u0 and qty = q1*D + q0 (u0, q0 < D). Then
     * unit*qty*f/D = u1*qty*f + u0*q1*f + u0*q0*f/D. All terms are non-negative parts of the
     * result, so an overflow of any term (or of their sum) means the result does not fit either.
     * The last term has u0*q0*f < 10^18 and carries the only rounding.
     *
     * @throws \OverflowException
     */
    public static function lineNet(Line $line): int
    {
        $f = 10000 - $line->discountBp;
        if ($f === 0 || $line->unitMinor === 0) {
            return 0;
        }
        $d = self::NET_DIVISOR;
        $u1 = intdiv($line->unitMinor, $d);
        $u0 = $line->unitMinor % $d;
        $q1 = intdiv($line->qtyMilli, $d);
        $q0 = $line->qtyMilli % $d;

        $whole = self::checked(self::checked($u1 * $line->qtyMilli) * $f);
        $whole = self::checked($whole + self::checked(self::checked($u0 * $q1) * $f));
        $tail = $u0 * $q0 * $f; // < 10^7 * 10^7 * 10^4 = 10^18, fits
        return self::checked($whole + Rounding::divHalfUp($tail, $d));
    }

    /**
     * round_half_up(vatMinor x 10^6 / rateScaled) for a rate already validated and scaled by 10^6.
     * Public for tests; use compute().
     *
     * @throws \OverflowException
     */
    public static function vatToEur(int $vatMinor, int $rateScaled): int
    {
        $q = intdiv($vatMinor, $rateScaled);
        $r = $vatMinor % $rateScaled;          // < 10^12
        $rm = $r * self::RATE_SCALE;           // < 10^18, fits
        return self::checked(self::checked($q * self::RATE_SCALE) + Rounding::divHalfUp($rm, $rateScaled));
    }

    /**
     * "4.2650" -> 4265000 (rate x 10^6). Up to 6 integer digits and 6 decimals, dot only, > 0.
     *
     * @throws \InvalidArgumentException
     */
    public static function parseRate(string $rate): int
    {
        if (preg_match('/\A([0-9]{1,6})(?:\.([0-9]{1,6}))?\z/', $rate, $m) !== 1) {
            throw new \InvalidArgumentException('Invalid exchange rate format.');
        }
        $scaled = (int) $m[1] * self::RATE_SCALE + (int) str_pad($m[2] ?? '', 6, '0');
        if ($scaled <= 0) {
            throw new \InvalidArgumentException('Exchange rate must be greater than zero.');
        }
        return $scaled;
    }

    /** PHP turns int overflow into float; detect that. */
    private static function checked(int|float $v): int
    {
        if (is_float($v)) {
            throw new \OverflowException('Amount overflows the integer range');
        }
        return $v;
    }
}
