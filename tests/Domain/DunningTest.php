<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Dunning;
use App\Domain\DunningLevel;
use App\Domain\DunningSettings;
use App\Domain\FlatFeeNotAllowed;
use App\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class DunningTest extends TestCase
{
    private static function eur(int $m): Money
    {
        return Money::of($m, 'EUR');
    }

    // ---- suggest -------------------------------------------------------------------------

    /** @return iterable<string, array{int, ?DunningLevel, ?DunningLevel}> */
    public static function suggestExamples(): iterable
    {
        yield [6, null, null];
        yield [7, null, DunningLevel::Reminder];
        yield [20, null, DunningLevel::Reminder];
        yield [21, null, DunningLevel::First];
        yield [21, DunningLevel::First, null];
        yield [38, DunningLevel::First, DunningLevel::Second];
        yield [60, DunningLevel::Second, DunningLevel::Final];
        yield [60, DunningLevel::Final, null];
        yield [100, null, DunningLevel::Final];
        yield [7, DunningLevel::Reminder, null];
        yield [49, DunningLevel::Final, null];
        yield [0, null, null];
        yield [-5, null, null];
        yield [-5, DunningLevel::Reminder, null];
    }

    #[DataProvider('suggestExamples')]
    public function testSuggestExamples(int $days, ?DunningLevel $last, ?DunningLevel $expected): void
    {
        self::assertSame($expected, Dunning::suggest($days, $last, DunningSettings::defaults()));
    }

    /** @return iterable<string, array{list<int>}> */
    public static function thresholdSets(): iterable
    {
        yield 'default' => [[7, 21, 35, 49]];
        yield 'custom' => [[3, 10, 20, 30]];
        yield 'one day steps' => [[1, 2, 3, 4]];
        yield 'wide' => [[30, 60, 90, 365]];
    }

    /** @param list<int> $t */
    #[DataProvider('thresholdSets')]
    public function testSuggestMatrix(array $t): void
    {
        $s = new DunningSettings($t, null, 900, 500, 4000, 0);
        $lasts = [null, ...DunningLevel::cases()];
        for ($days = -2; $days <= 400; $days++) {
            foreach ($lasts as $last) {
                $eligible = null;
                foreach ($t as $i => $threshold) {
                    if ($days >= 1 && $threshold <= $days) {
                        $eligible = $i;
                    }
                }
                $expected = $eligible !== null && ($last === null || $eligible > $last->value)
                    ? DunningLevel::from($eligible) : null;
                self::assertSame($expected, Dunning::suggest($days, $last, $s), "days={$days} last=" . ($last?->name ?? 'none'));
            }
        }
    }

    public function testSuggestAtExactThresholdAndOneBefore(): void
    {
        $s = new DunningSettings([3, 10, 20, 30], null, 900, 500, 4000, 0);
        self::assertNull(Dunning::suggest(2, null, $s));
        self::assertSame(DunningLevel::Reminder, Dunning::suggest(3, null, $s));
        self::assertSame(DunningLevel::Reminder, Dunning::suggest(9, null, $s));
        self::assertSame(DunningLevel::First, Dunning::suggest(10, null, $s));
        self::assertSame(DunningLevel::Final, Dunning::suggest(30, DunningLevel::Second, $s));
    }

    public function testLevelTranslationKeysExistInAllLanguages(): void
    {
        foreach (['de', 'en', 'pl'] as $lang) {
            $messages = require __DIR__ . "/../../lang/{$lang}.php";
            foreach (DunningLevel::cases() as $level) {
                self::assertSame('dunning.level_' . $level->value, $level->translationKey());
                self::assertArrayHasKey($level->translationKey(), $messages);
            }
        }
    }

    // ---- daysOverdue ---------------------------------------------------------------------

    /** @return iterable<string, array{string, string, int}> */
    public static function overdueCases(): iterable
    {
        yield 'due today' => ['2026-11-20', '2026-11-20 12:00:00 Europe/Berlin', 0];
        yield 'yesterday' => ['2026-11-19', '2026-11-20 00:00:00 Europe/Berlin', 1];
        yield 'tomorrow' => ['2026-11-21', '2026-11-20 23:59:59 Europe/Berlin', -1];
        yield 'month boundary' => ['2026-01-31', '2026-03-01 10:00:00 Europe/Berlin', 29];
        yield 'year boundary' => ['2026-12-31', '2027-01-01 10:00:00 Europe/Berlin', 1];
        yield 'leap day' => ['2028-02-29', '2028-03-01 10:00:00 Europe/Berlin', 1];
        yield 'over leap day' => ['2028-02-28', '2028-03-01 10:00:00 Europe/Berlin', 2];
        yield 'non-leap february' => ['2027-02-28', '2027-03-01 10:00:00 Europe/Berlin', 1];
        yield 'utc late evening is next day in Berlin' => ['2026-12-31', '2026-12-31 23:30:00 UTC', 1];
        yield 'summer utc late evening' => ['2026-07-01', '2026-07-01 22:30:00 UTC', 1];
        yield 'utc just before Berlin midnight' => ['2026-12-31', '2026-12-31 22:59:59 UTC', 0];
        yield 'other zone late' => ['2026-11-20', '2026-11-20 20:00:00 America/New_York', 1];
        yield 'dst spring forward' => ['2026-03-28', '2026-03-30 12:00:00 Europe/Berlin', 2];
        yield 'dst fall back' => ['2026-10-24', '2026-10-26 12:00:00 Europe/Berlin', 2];
        yield 'long' => ['2025-11-20', '2026-11-20 12:00:00 Europe/Berlin', 365];
    }

    #[DataProvider('overdueCases')]
    public function testDaysOverdue(string $due, string $now, int $expected): void
    {
        self::assertSame($expected, Dunning::daysOverdue($due, new \DateTimeImmutable($now)));
    }

    /** @return iterable<string, array{string}> */
    public static function badDates(): iterable
    {
        yield 'feb 30' => ['2026-02-30'];
        yield 'empty' => [''];
        yield 'german' => ['31.12.2026'];
        yield 'no padding' => ['2026-1-5'];
        yield 'trailing' => ['2026-12-31 '];
        yield 'time' => ['2026-12-31T10:00'];
        yield 'text' => ['soon'];
        yield 'feb 29 non-leap' => ['2027-02-29'];
    }

    #[DataProvider('badDates')]
    public function testDaysOverdueRejectsInvalidDates(string $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Dunning::daysOverdue($bad, new \DateTimeImmutable('2026-11-20 12:00:00 UTC'));
    }

    // ---- rate ----------------------------------------------------------------------------

    public function testAnnualRate(): void
    {
        $s = new DunningSettings([7, 21, 35, 49], 127, 900, 500, 4000, 0);
        self::assertSame(1027, Dunning::annualRateBp($s, true));
        self::assertSame(627, Dunning::annualRateBp($s, false));
        self::assertNull(Dunning::annualRateBp(DunningSettings::defaults(), true));
        self::assertNull(Dunning::annualRateBp(DunningSettings::defaults(), false));
        $neg = new DunningSettings([7, 21, 35, 49], -50, 900, 500, 4000, 0);
        self::assertSame(850, Dunning::annualRateBp($neg, true));
    }

    // ---- interest ------------------------------------------------------------------------

    public function testInterestExamples(): void
    {
        self::assertEquals(self::eur(844), Dunning::interest(self::eur(142800), 21, 127, 900));
        self::assertEquals(self::eur(9000), Dunning::interest(self::eur(100000), 365, 0, 900));
        self::assertEquals(self::eur(25), Dunning::interest(self::eur(100000), 1, 0, 900));
        self::assertNull(Dunning::interest(self::eur(100000), 30, null, 900));
        self::assertEquals(self::eur(0), Dunning::interest(self::eur(100000), 0, 127, 900));
        self::assertEquals(self::eur(0), Dunning::interest(self::eur(100000), -4, 127, 900));
        self::assertEquals(self::eur(0), Dunning::interest(self::eur(0), 30, 127, 900));
        self::assertEquals(self::eur(0), Dunning::interest(self::eur(-5000), 30, 127, 900));
    }

    public function testInterestKeepsCurrency(): void
    {
        $i = Dunning::interest(Money::of(100000, 'PLN'), 365, 0, 900);
        self::assertSame('PLN', $i?->currency());
        self::assertSame(9000, $i?->minor());
    }

    public function testNegativeBaseRate(): void
    {
        // rate 850 bp: 100000 x 850 x 365 / 3_650_000 = 8500
        self::assertEquals(self::eur(8500), Dunning::interest(self::eur(100000), 365, -50, 900));
        self::assertEquals(self::eur(0), Dunning::interest(self::eur(100000), 365, -1000, 900));
        self::assertEquals(self::eur(0), Dunning::interest(self::eur(100000), 365, -900, 900));
    }

    public function testHalfUpBoundary(): void
    {
        // 1825 x 1000 x 1 / 3_650_000 = exactly 0.5 -> 1 ; 1824 -> 0.4997 -> 0 ; 5475 -> 1.5 -> 2
        self::assertEquals(self::eur(1), Dunning::interest(self::eur(1825), 1, 0, 1000));
        self::assertEquals(self::eur(0), Dunning::interest(self::eur(1824), 1, 0, 1000));
        self::assertEquals(self::eur(2), Dunning::interest(self::eur(5475), 1, 0, 1000));
        self::assertEquals(self::eur(2), Dunning::interest(self::eur(5476), 1, 0, 1000));
    }

    public function testLargeValuesExactOrOverflow(): void
    {
        // 99_999_999_999 x 3000 x 36500 / 3_650_000 = 99_999_999_999 x 30 = 2_999_999_999_970 exactly
        self::assertEquals(self::eur(2_999_999_999_970), Dunning::interest(self::eur(99_999_999_999), 36500, 0, 3000));
        foreach ([
            [PHP_INT_MAX, 365, 0, 900],
            [PHP_INT_MAX, 1, 0, 10_000_000],
            [1_000_000_000_000_000, 36500, 0, 100_000],
            [1, PHP_INT_MAX, 0, PHP_INT_MAX],
        ] as [$m, $d, $b, $sur]) {
            try {
                $r = Dunning::interest(self::eur($m), $d, $b, $sur);
                // no bcmath/gmp here: exactness of big values is covered by the Python oracle run; never negative
                self::assertGreaterThanOrEqual(0, $r?->minor());
            } catch (\OverflowException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\OverflowException::class);
        Dunning::interest(self::eur(5), 1, PHP_INT_MAX, 1);
    }

    public function testRandomAgainstExactReference(): void
    {
        $rng = new Randomizer(new Mt19937(20261120));
        for ($i = 0; $i < 1000; $i++) {
            $minor = $rng->getInt(1, 5_000_000_000);
            $days = $rng->getInt(1, 3650);
            $base = $rng->getInt(-1000, 2000);
            $sur = $rng->getInt(0, 1500);
            $rate = $base + $sur;
            $n = $minor * $rate * $days; // < 5e9 x 3500 x 3650 = 6.4e16, fits
            $expected = $rate <= 0 ? 0 : intdiv($n, 3_650_000) + (($n % 3_650_000) * 2 >= 3_650_000 ? 1 : 0);
            self::assertSame($expected, Dunning::interest(self::eur($minor), $days, $base, $sur)?->minor(), "{$minor} {$days} {$base} {$sur}");
        }
    }

    public function testMonotonicity(): void
    {
        $rng = new Randomizer(new Mt19937(7));
        for ($i = 0; $i < 300; $i++) {
            $minor = $rng->getInt(1, 1_000_000_000);
            $rate = $rng->getInt(1, 2000);
            $days = $rng->getInt(1, 2000);
            $a = Dunning::interest(self::eur($minor), $days, 0, $rate)?->minor();
            $b = Dunning::interest(self::eur($minor), $days + 1, 0, $rate)?->minor();
            $c = Dunning::interest(self::eur($minor + $rng->getInt(0, 100000)), $days, 0, $rate)?->minor();
            self::assertGreaterThanOrEqual($a, $b);
            self::assertGreaterThanOrEqual($a, $c);
        }
    }

    // ---- fees ----------------------------------------------------------------------------

    public function testFlatFee(): void
    {
        self::assertEquals(self::eur(4000), Dunning::flatFee(true, DunningSettings::defaults()));
        $s = new DunningSettings([7, 21, 35, 49], null, 900, 500, 5000, 0);
        self::assertEquals(self::eur(5000), Dunning::flatFee(true, $s));
    }

    public function testFlatFeeConsumerThrows(): void
    {
        $this->expectException(FlatFeeNotAllowed::class);
        Dunning::flatFee(false, DunningSettings::defaults());
    }

    public function testFlatFeeNotAllowedIsDomainException(): void
    {
        self::assertInstanceOf(\DomainException::class, new FlatFeeNotAllowed());
    }

    public function testFee(): void
    {
        self::assertEquals(self::eur(0), Dunning::fee(DunningSettings::defaults()));
        self::assertEquals(self::eur(250), Dunning::fee(new DunningSettings([7, 21, 35, 49], null, 900, 500, 4000, 250)));
    }

    public function testFeesAllowedFor(): void
    {
        self::assertTrue(Dunning::feesAllowedFor('EUR'));
        self::assertFalse(Dunning::feesAllowedFor('PLN'));
        self::assertFalse(Dunning::feesAllowedFor('USD'));
        self::assertFalse(Dunning::feesAllowedFor('eur'));
        self::assertFalse(Dunning::feesAllowedFor(''));
    }

    // ---- claimTotal ----------------------------------------------------------------------

    public function testClaimTotal(): void
    {
        $o = self::eur(142800);
        self::assertEquals($o, Dunning::claimTotal($o, null, null, null));
        self::assertEquals(self::eur(142800 + 844), Dunning::claimTotal($o, self::eur(844), null, null));
        self::assertEquals(self::eur(142800 + 844 + 4000 + 250), Dunning::claimTotal($o, self::eur(844), self::eur(4000), self::eur(250)));
        self::assertEquals(self::eur(146800), Dunning::claimTotal($o, null, self::eur(4000), null));
        self::assertEquals(Money::of(5000, 'PLN'), Dunning::claimTotal(Money::of(5000, 'PLN'), Money::of(0, 'PLN'), null, null));
    }

    public function testClaimTotalCurrencyMismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Dunning::claimTotal(Money::of(5000, 'PLN'), null, self::eur(4000), null);
    }

    public function testClaimTotalInterestMismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Dunning::claimTotal(self::eur(5000), Money::of(10, 'USD'), null, null);
    }
}
