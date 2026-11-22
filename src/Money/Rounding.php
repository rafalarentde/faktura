<?php
declare(strict_types=1);

namespace App\Money;

/** Exact integer rounding helpers (no floats). */
final class Rounding
{
    /** round_half_up($n / $d) for $n >= 0, $d > 0 and $d <= 10^12 (so the remainder doubled cannot overflow). */
    public static function divHalfUp(int $n, int $d): int
    {
        return intdiv($n, $d) + (($n % $d) * 2 >= $d ? 1 : 0);
    }
}
