<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Line;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LineTest extends TestCase
{
    public function testValueObjectFields(): void
    {
        $l = new Line(2500, 333, 1000, 1900);
        self::assertSame([2500, 333, 1000, 1900], [$l->qtyMilli, $l->unitMinor, $l->discountBp, $l->vatBp]);
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function invalid(): iterable
    {
        yield 'qty 0' => [0, 100, 0, 1900];
        yield 'qty negative' => [-1, 100, 0, 1900];
        yield 'unit negative' => [1000, -1, 0, 1900];
        yield 'discount negative' => [1000, 100, -1, 1900];
        yield 'discount > 100%' => [1000, 100, 10001, 1900];
        yield 'vat 1500' => [1000, 100, 0, 1500];
        yield 'vat -1' => [1000, 100, 0, -1];
        yield 'vat 2100' => [1000, 100, 0, 2100];
    }

    #[DataProvider('invalid')]
    public function testInvalidRejected(int $q, int $u, int $d, int $v): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Line($q, $u, $d, $v);
    }

    public function testBoundariesAccepted(): void
    {
        foreach ([1900, 700, 0] as $bp) {
            self::assertSame($bp, (new Line(1, 0, 10000, $bp))->vatBp);
        }
        self::assertSame(0, (new Line(1, 0, 0, 0))->discountBp);
    }
}
