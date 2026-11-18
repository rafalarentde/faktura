<?php
declare(strict_types=1);

namespace Tests\Money;

use App\Money\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testOfExposesMinorAndCurrency(): void
    {
        $m = Money::of(1234, 'PLN');
        $this->assertSame(1234, $m->minor());
        $this->assertSame('PLN', $m->currency());
    }

    public function testUnknownCurrencyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of(100, 'GBP');
    }

    public function testAddSubAreImmutable(): void
    {
        $a = Money::of(1000, 'EUR');
        $b = Money::of(250, 'EUR');
        $this->assertSame(1250, $a->add($b)->minor());
        $this->assertSame(750, $a->sub($b)->minor());
        $this->assertSame(1000, $a->minor());
        $this->assertSame(-750, $b->sub($a)->minor());
    }

    public function testCrossCurrencyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of(1, 'EUR')->add(Money::of(1, 'USD'));
    }

    public function testCrossCurrencySubRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of(1, 'EUR')->sub(Money::of(1, 'PLN'));
    }

    public function testPercentBp(): void
    {
        $this->assertSame(18240, Money::of(96000, 'EUR')->percentBp(1900)->minor());
        $this->assertSame(0, Money::of(0, 'EUR')->percentBp(1900)->minor());
        $this->assertSame('EUR', Money::of(100, 'EUR')->percentBp(100)->currency());
    }

    public function testPercentBpRoundsHalfUp(): void
    {
        // 50 * 1% = 0.5 -> 1 ; 49 * 1% = 0.49 -> 0
        $this->assertSame(1, Money::of(50, 'EUR')->percentBp(100)->minor());
        $this->assertSame(0, Money::of(49, 'EUR')->percentBp(100)->minor());
        // 1 * 19.00% = 0.19 -> 0 ; 3 * 19% = 0.57 -> 1
        $this->assertSame(0, Money::of(1, 'EUR')->percentBp(1900)->minor());
        $this->assertSame(1, Money::of(3, 'EUR')->percentBp(1900)->minor());
    }

    public function testMulQty(): void
    {
        $this->assertSame(90000, Money::of(45000, 'EUR')->mulQty(2000)->minor());
        $this->assertSame(833, Money::of(333, 'EUR')->mulQty(2500)->minor()); // 832.5 -> 833
        $this->assertSame(832, Money::of(333, 'EUR')->mulQty(2499)->minor()); // 832.167
    }

    public function testNegativeAmountsRoundHalfAwayFromZero(): void
    {
        $this->assertSame(-833, Money::of(-333, 'EUR')->mulQty(2500)->minor()); // -832.5
        $this->assertSame(-1, Money::of(-50, 'EUR')->percentBp(100)->minor());  // -0.5
        $this->assertSame(0, Money::of(-49, 'EUR')->percentBp(100)->minor());   // -0.49
        $this->assertSame(-18240, Money::of(-96000, 'EUR')->percentBp(1900)->minor());
        $this->assertSame(-833, Money::of(333, 'EUR')->mulQty(-2500)->minor());
    }

    public function testNegateAndIsZero(): void
    {
        $this->assertSame(-5, Money::of(5, 'EUR')->negate()->minor());
        $this->assertSame(5, Money::of(-5, 'EUR')->negate()->minor());
        $this->assertTrue(Money::of(0, 'EUR')->isZero());
        $this->assertFalse(Money::of(1, 'EUR')->isZero());
    }

    public function testEquals(): void
    {
        $this->assertTrue(Money::of(5, 'EUR')->equals(Money::of(5, 'EUR')));
        $this->assertFalse(Money::of(5, 'EUR')->equals(Money::of(6, 'EUR')));
        $this->assertFalse(Money::of(5, 'EUR')->equals(Money::of(5, 'USD')));
    }

    public function testAddOverflowThrows(): void
    {
        $this->expectException(\OverflowException::class);
        Money::of(PHP_INT_MAX, 'EUR')->add(Money::of(1, 'EUR'));
    }

    public function testSubOverflowThrows(): void
    {
        $this->expectException(\OverflowException::class);
        Money::of(PHP_INT_MIN, 'EUR')->sub(Money::of(1, 'EUR'));
    }

    public function testNegateOverflowThrows(): void
    {
        $this->expectException(\OverflowException::class);
        Money::of(PHP_INT_MIN, 'EUR')->negate();
    }

    public function testPercentBpOverflowThrows(): void
    {
        $this->expectException(\OverflowException::class);
        Money::of(PHP_INT_MAX, 'EUR')->percentBp(10000 * 2);
    }

    public function testMulQtyOverflowThrows(): void
    {
        $this->expectException(\OverflowException::class);
        Money::of(PHP_INT_MAX, 'EUR')->mulQty(2000);
    }

    public function testNearMaxWithoutOverflowIsExact(): void
    {
        $this->assertSame(PHP_INT_MAX, Money::of(PHP_INT_MAX - 1, 'EUR')->add(Money::of(1, 'EUR'))->minor());
        $this->assertSame(PHP_INT_MAX, Money::of(PHP_INT_MAX, 'EUR')->mulQty(1000)->minor());
    }
}
