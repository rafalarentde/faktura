<?php
declare(strict_types=1);

namespace App\Domain;

use App\Money\Money;
use App\Money\Rounding;

/**
 * Payment-reminder (Mahnung) rules. Pure functions, exact integer arithmetic.
 *
 * Statutory default interest (§ 288 BGB): base rate + 9 pp for B2B, + 5 pp for consumers; flat fee
 * 40 EUR for B2B only (§ 288 Abs. 5). All of this is "do weryfikacji" with the Steuerberater. The
 * base rate (Basiszinssatz) changes every 6 months and is NEVER defaulted: the user enters it in the
 * settings; until then interest is null.
 */
final class Dunning
{
    private const YEAR_DAYS = 365;
    private const BP = 10_000;

    /** Highest level whose threshold <= days and which is above $lastSent; null otherwise. */
    public static function suggest(int $daysOverdue, ?DunningLevel $lastSent, DunningSettings $s): ?DunningLevel
    {
        if ($daysOverdue <= 0) {
            return null;
        }
        $best = null;
        foreach (DunningLevel::cases() as $level) {
            if ($s->thresholds[$level->value] <= $daysOverdue) {
                $best = $level;
            }
        }
        if ($best === null || ($lastSent !== null && $best->value <= $lastSent->value)) {
            return null;
        }
        return $best;
    }

    /**
     * Signed whole days from the due date (Y-m-d) to the Berlin calendar date of $today.
     * Due today = 0, due yesterday = 1, due tomorrow = -1.
     *
     * @throws \InvalidArgumentException
     */
    public static function daysOverdue(string $dueDate, \DateTimeImmutable $today): int
    {
        $utc = new \DateTimeZone('UTC');
        $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate, $utc);
        if ($due === false || $due->format('Y-m-d') !== $dueDate) {
            throw new \InvalidArgumentException('Invalid due date.');
        }
        $berlinDate = $today->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
        $now = new \DateTimeImmutable($berlinDate . ' 00:00:00', $utc);
        return intdiv($now->getTimestamp() - $due->getTimestamp(), 86400);
    }

    /** Annual default-interest rate in bp, or null while the base rate has not been entered. */
    public static function annualRateBp(DunningSettings $s, bool $isCompany): ?int
    {
        if ($s->baseRateBp === null) {
            return null;
        }
        return $s->baseRateBp + ($isCompany ? $s->b2bSurchargeBp : $s->b2cSurchargeBp);
    }

    /**
     * round_half_up(outstanding x (base + surcharge) x days / (10000 x 365)), one rounding, exact.
     * Null when the base rate is unknown; zero for days <= 0, outstanding <= 0 or a rate <= 0
     * (the base rate may be negative, interest never is).
     *
     * With D = 10000 x 365 write outstanding = q*D + r (r < D):
     * outstanding x rate x days / D = q x rate x days + r x rate x days / D, the latter carrying the only
     * rounding. Any overflowing term means the result does not fit (or is not exactly computable): throws.
     *
     * @throws \OverflowException
     */
    public static function interest(Money $outstanding, int $days, ?int $baseRateBp, int $surchargeBp): ?Money
    {
        if ($baseRateBp === null) {
            return null;
        }
        $cur = $outstanding->currency();
        $minor = $outstanding->minor();
        $rate = self::checked($baseRateBp + $surchargeBp);
        if ($days <= 0 || $minor <= 0 || $rate <= 0) {
            return Money::of(0, $cur);
        }
        $d = self::BP * self::YEAR_DAYS;
        $q = intdiv($minor, $d);
        $r = $minor % $d;
        $whole = self::checked(self::checked($q * $rate) * $days);
        $tail = self::checked(self::checked($r * $rate) * $days);
        return Money::of(self::checked($whole + Rounding::divHalfUp($tail, $d)), $cur);
    }

    /** Statutory lump sum (EUR), businesses only. @throws FlatFeeNotAllowed */
    public static function flatFee(bool $isCompany, DunningSettings $s): Money
    {
        if (!$isCompany) {
            throw new FlatFeeNotAllowed('The flat fee may only be charged to businesses.');
        }
        return Money::of($s->flatMinor, 'EUR');
    }

    /** Optional per-dunning fee (EUR). */
    public static function fee(DunningSettings $s): Money
    {
        return Money::of($s->feeMinor, 'EUR');
    }

    /** Flat fee and dunning fee are EUR amounts only; no conversion. */
    public static function feesAllowedFor(string $currency): bool
    {
        return $currency === 'EUR';
    }

    /** @throws \InvalidArgumentException on currency mismatch */
    public static function claimTotal(Money $outstanding, ?Money $interest, ?Money $flatFee, ?Money $fee): Money
    {
        $sum = $outstanding;
        foreach ([$interest, $flatFee, $fee] as $part) {
            if ($part === null) {
                continue;
            }
            if ($part->currency() !== $outstanding->currency()) {
                throw new \InvalidArgumentException('Claim parts must share the currency of the outstanding amount.');
            }
            $sum = $sum->add($part);
        }
        return $sum;
    }

    private static function checked(int|float $v): int
    {
        if (is_float($v)) {
            throw new \OverflowException('Amount overflows the integer range');
        }
        return $v;
    }
}
