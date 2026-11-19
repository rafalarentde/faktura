<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Line;
use App\Domain\Totals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotalsTest extends TestCase
{
    private static function l(int $qtyMilli, int $unit, int $vat, int $disc = 0): Line
    {
        return new Line($qtyMilli, $unit, $disc, $vat);
    }

    /** @return iterable<string, array{int, int, int, int}> qtyMilli, unitMinor, discountBp, expected net */
    public static function nets(): iterable
    {
        yield '2 x 450.00' => [2000, 45000, 0, 90000];
        yield '2.5 x 333 half-up (832.5)' => [2500, 333, 0, 833];
        yield '10% off 1999 (1799.1)' => [1000, 1999, 1000, 1799];
        yield '50% off 1999 (999.5)' => [1000, 1999, 5000, 1000];
        yield '100% discount' => [1000, 1999, 10000, 0];
        yield 'zero price' => [5000, 0, 0, 0];
        yield '0.001 x 500 = 0.5 rounds up' => [1, 500, 0, 1];
        yield '0.001 x 499 rounds down' => [1, 499, 0, 0];
        yield 'big exact' => [1000, 9_000_000_000_000_000_000, 0, 9_000_000_000_000_000_000];
        yield 'big with discount' => [1000, 9_000_000_000_000_000_000, 5000, 4_500_000_000_000_000_000];
        yield 'raw product 1e21 but result fits' => [1_000_000_000, 1_000_000_000_000, 0, 1_000_000_000_000_000_000];
        yield 'rounding in last step with big operands' => [999_999_999, 999_999_999_999, 1, 999_899_998_999_100_100];
    }

    #[DataProvider('nets')]
    public function testLineNet(int $qty, int $unit, int $disc, int $expected): void
    {
        self::assertSame($expected, Totals::lineNet(new Line($qty, $unit, $disc, 1900)));
    }

    public function testLineNetOverflow(): void
    {
        $this->expectException(\OverflowException::class);
        Totals::lineNet(new Line(PHP_INT_MAX, PHP_INT_MAX, 0, 1900));
    }

    public function testMockupExample(): void
    {
        $t = Totals::compute([
            self::l(2000, 45000, 1900),
            self::l(1000, 6000, 1900),
            self::l(5000, 1200, 700),
        ], false, 'EUR');
        self::assertSame(102000, $t->netMinor);
        self::assertSame([1900 => 18240, 700 => 420], $t->vat);
        self::assertSame([1900 => 96000, 700 => 6000], $t->nets);
        self::assertSame(18660, $t->vatMinor);
        self::assertSame(120660, $t->grossMinor);
        self::assertSame(18660, $t->vatEurMinor);
        self::assertSame([1900, 700], array_keys($t->vat));
    }

    public function testVatIsComputedOnSumNotPerLine(): void
    {
        $lines = [self::l(1000, 33, 1900), self::l(1000, 33, 1900), self::l(1000, 33, 1900)];
        $t = Totals::compute($lines, false, 'EUR');
        self::assertSame(99, $t->netMinor);
        self::assertSame(19, $t->vatMinor); // 18.81 -> 19, per line it would be 3 x 6 = 18
        self::assertSame(118, $t->grossMinor);

        $t7 = Totals::compute([self::l(1000, 33, 700), self::l(1000, 33, 700), self::l(1000, 33, 700)], false, 'EUR');
        self::assertSame(7, $t7->vatMinor); // 6.93 -> 7, per line 3 x 2 = 6
    }

    public function testOrderingOfRatesAndAbsentRates(): void
    {
        $t = Totals::compute([self::l(1000, 1000, 700), self::l(1000, 1000, 0), self::l(1000, 1000, 1900)], false, 'EUR');
        self::assertSame([1900, 700, 0], array_keys($t->vat));
        self::assertSame([1900, 700, 0], array_keys($t->nets));
        self::assertSame(0, $t->vat[0]);

        $only7 = Totals::compute([self::l(1000, 1000, 700)], false, 'EUR');
        self::assertSame([700 => 70], $only7->vat);
        self::assertArrayNotHasKey(1900, $only7->vat);
    }

    public function testReverseCharge(): void
    {
        $t = Totals::compute([self::l(2000, 45000, 1900), self::l(5000, 1200, 700)], true, 'EUR');
        self::assertSame([], $t->vat);
        self::assertSame(0, $t->vatMinor);
        self::assertSame(96000, $t->netMinor);
        self::assertSame($t->netMinor, $t->grossMinor);
        self::assertSame([0 => 96000], $t->nets);
        self::assertSame(0, $t->vatEurMinor);
    }

    public function testReverseCharge_foreignCurrencyNeedsNoRate(): void
    {
        $t = Totals::compute([self::l(1000, 1000, 1900)], true, 'PLN');
        self::assertSame(0, $t->vatEurMinor);
        $t = Totals::compute([self::l(1000, 1000, 1900)], true, 'PLN', '4.2650');
        self::assertSame(0, $t->vatEurMinor);
        $this->expectException(\InvalidArgumentException::class);
        Totals::compute([self::l(1000, 1000, 1900)], true, 'PLN', '4,2');
    }

    public function testZeroValueAndFullDiscountLines(): void
    {
        $t = Totals::compute([self::l(1000, 0, 1900), self::l(1000, 500, 1900, 10000)], false, 'EUR');
        self::assertSame(0, $t->netMinor);
        self::assertSame(0, $t->grossMinor);
        self::assertSame([1900 => 0], $t->vat);
    }

    public function testFractionalQuantity(): void
    {
        $t = Totals::compute([self::l(2500, 333, 1900)], false, 'EUR');
        self::assertSame(833, $t->netMinor);
        self::assertSame(158, $t->vatMinor); // 158.27
    }

    public function testEmptyLinesRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Totals::compute([], false, 'EUR');
    }

    public function testUnsupportedCurrencyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Totals::compute([self::l(1000, 1, 1900)], false, 'GBP');
    }

    public function testNonLineElementRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Totals::compute([['qty' => 1]], false, 'EUR'); // @phpstan-ignore-line
    }

    /** @return iterable<string, array{string, string, int, int}> currency, rate, vatMinor, expected eur */
    public static function eurCases(): iterable
    {
        yield 'PLN 4.2650' => ['PLN', '4.2650', 100000, 23447];
        yield 'PLN 4.265' => ['PLN', '4.265', 100000, 23447];
        yield 'USD 1.0870 (919.96)' => ['USD', '1.0870', 1000, 920];
        yield 'zero vat' => ['PLN', '4.2650', 0, 0];
        yield 'huge rate' => ['PLN', '999999.999999', 100000, 0];
        yield 'integer rate' => ['PLN', '4', 100000, 25000];
        yield 'rate 1 for USD' => ['USD', '1', 12345, 12345];
        yield 'half rounds up' => ['USD', '2', 5, 3];
        yield 'rate 6 decimals' => ['PLN', '0.000001', 3, 3_000_000];
    }

    #[DataProvider('eurCases')]
    public function testVatInEur(string $cur, string $rate, int $vatMinor, int $expected): void
    {
        self::assertSame($expected, Totals::vatToEur($vatMinor, Totals::parseRate($rate)));
    }

    public function testVatEurWithDirectVatAmount(): void
    {
        // 100000 minor of VAT: net 526315.79 not exact, so use 7%: net 1_428_571 -> vat 100000 (99999.97 -> 100000).
        $t = Totals::compute([self::l(1000, 1_428_571, 700)], false, 'PLN', '4.2650');
        self::assertSame(100000, $t->vatMinor);
        self::assertSame(23447, $t->vatEurMinor);

        $u = Totals::compute([self::l(1000, 1_428_571, 700)], false, 'PLN', '4.265');
        self::assertSame(23447, $u->vatEurMinor);
    }

    public function testUsdExample(): void
    {
        // vat 1000: 0% + ... use 7% on 14286 -> 1000.02 -> 1000
        $t = Totals::compute([self::l(1000, 14286, 700)], false, 'USD', '1.0870');
        self::assertSame(1000, $t->vatMinor);
        self::assertSame(920, $t->vatEurMinor);
    }

    /** @return iterable<string, array{string}> */
    public static function badRates(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zero decimals' => ['0.000000'];
        yield 'negative' => ['-1'];
        yield 'letters' => ['abc'];
        yield 'comma' => ['4,265'];
        yield 'seven decimals' => ['4.2650000'];
        yield 'empty' => [''];
        yield 'trailing dot' => ['4.'];
        yield 'leading dot' => ['.5'];
        yield 'seven int digits' => ['1234567'];
        yield 'space' => [' 4.2'];
        yield 'plus' => ['+4.2'];
        yield 'exponent' => ['1e3'];
    }

    #[DataProvider('badRates')]
    public function testBadRateRejected(string $rate): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Totals::compute([self::l(1000, 1000, 1900)], false, 'PLN', $rate);
    }

    public function testForeignCurrencyRequiresRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Totals::compute([self::l(1000, 1000, 1900)], false, 'PLN');
    }

    public function testUsdRequiresRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Totals::compute([self::l(1000, 1000, 1900)], false, 'USD', null);
    }

    public function testEurAcceptsNullAndUnitRates(): void
    {
        foreach ([null, '1', '1.0000', '1.000000'] as $rate) {
            $t = Totals::compute([self::l(1000, 1000, 1900)], false, 'EUR', $rate);
            self::assertSame(190, $t->vatEurMinor);
        }
    }

    public function testEurRejectsNonUnitRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Totals::compute([self::l(1000, 1000, 1900)], false, 'EUR', '1.0870');
    }

    public function testEurRejectsMalformedRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Totals::compute([self::l(1000, 1000, 1900)], false, 'EUR', 'abc');
    }

    public function testSumOverflowThrows(): void
    {
        $this->expectException(\OverflowException::class);
        Totals::compute([self::l(1000, PHP_INT_MAX, 0), self::l(1000, 1, 0)], false, 'EUR');
    }

    public function testGrossOverflowThrows(): void
    {
        $this->expectException(\OverflowException::class);
        Totals::compute([self::l(1000, PHP_INT_MAX - 10, 1900)], false, 'EUR');
    }

    public function testPropertyStyleRandomLines(): void
    {
        $rates = [1900, 700, 0];
        for ($seed = 1; $seed <= 50; $seed++) {
            mt_srand($seed);
            $lines = [];
            for ($i = mt_rand(1, 12); $i > 0; $i--) {
                $lines[] = new Line(mt_rand(1, 20000), mt_rand(0, 500000), mt_rand(0, 10000), $rates[mt_rand(0, 2)]);
            }
            $rc = $seed % 5 === 0;
            $t = Totals::compute($lines, $rc, 'EUR');

            self::assertSame($t->netMinor + $t->vatMinor, $t->grossMinor, "seed $seed");
            self::assertSame(array_sum($t->vat), $t->vatMinor, "seed $seed");
            self::assertSame(array_sum($t->nets), $t->netMinor, "seed $seed");
            self::assertSame(array_sum(array_map(Totals::lineNet(...), $lines)), $t->netMinor, "seed $seed");
            if ($rc) {
                self::assertSame([], $t->vat);
            }

            $shuffled = $lines;
            shuffle($shuffled);
            $reversed = array_reverse($lines);
            foreach ([$shuffled, $reversed] as $other) {
                $o = Totals::compute($other, $rc, 'EUR');
                self::assertEquals($t, $o, "seed $seed order independence");
                self::assertSame(array_keys($t->vat), array_keys($o->vat));
            }
        }
    }
}
