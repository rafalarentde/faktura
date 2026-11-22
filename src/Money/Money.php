<?php
declare(strict_types=1);

namespace App\Money;

/**
 * Immutable money amount in integer minor units (cents/grosze).
 * All arithmetic is integer-only; results that would exceed the int range throw.
 * Rounding is half-up, i.e. half away from zero for negative amounts.
 */
final class Money
{
    private const CURRENCIES = ['EUR', 'PLN', 'USD'];

    private function __construct(
        private readonly int $minor,
        private readonly string $currency,
    ) {
    }

    public static function of(int $minor, string $currency): self
    {
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new \InvalidArgumentException("Unsupported currency: {$currency}");
        }
        return new self($minor, $currency);
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self(self::checked($this->minor + $other->minor), $this->currency);
    }

    public function sub(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self(self::checked($this->minor - $other->minor), $this->currency);
    }

    public function negate(): self
    {
        return new self(self::checked(-$this->minor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    /** Percentage in basis points (1900 = 19.00 %), rounded half-up. */
    public function percentBp(int $bp): self
    {
        return new self(self::mulDiv($this->minor, $bp, 10000), $this->currency);
    }

    /** minor × qtyMilli / 1000 (quantity in thousandths), rounded half-up. */
    public function mulQty(int $qtyMilli): self
    {
        return new self(self::mulDiv($this->minor, $qtyMilli, 1000), $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}");
        }
    }

    /** PHP silently turns int overflow into float; detect that. */
    private static function checked(int|float $v): int
    {
        if (is_float($v)) {
            throw new \OverflowException('Money amount overflows the integer range');
        }
        return $v;
    }

    /**
     * round(n * m / d) half away from zero ($d > 0), integer-only. Splits n = q*d + r so the
     * result is exact whenever it fits in an int, even if n*m alone would not.
     */
    private static function mulDiv(int $n, int $m, int $d): int
    {
        $q = intdiv($n, $d);
        $r = $n % $d; // same sign as $n, |r| < d
        $rm = self::checked($r * $m);
        $part = intdiv($rm, $d);
        $rem = $rm % $d;
        if (abs($rem) * 2 >= $d) {
            $part += $rm < 0 ? -1 : 1;
        }
        return self::checked(self::checked($q * $m) + $part);
    }
}
