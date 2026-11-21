<?php
declare(strict_types=1);

namespace Tests\Money;

use App\Money\Formatter;
use App\Money\Money;
use PHPUnit\Framework\TestCase;

final class FormatterTest extends TestCase
{
    /** ICU emits U+00A0 / U+202F as separators; normalise to a plain space. */
    private static function n(string $s): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $s);
    }

    public function testMoneyDe(): void
    {
        $this->assertSame('1.206,60 €', self::n((new Formatter())->money(Money::of(120660, 'EUR'), 'de')));
    }

    public function testMoneyPl(): void
    {
        $this->assertSame('1 206,60 €', self::n((new Formatter())->money(Money::of(120660, 'EUR'), 'pl')));
    }

    public function testMoneyEn(): void
    {
        $this->assertSame('€1,206.60', self::n((new Formatter())->money(Money::of(120660, 'EUR'), 'en')));
    }

    public function testPlnInPlEndsWithZloty(): void
    {
        $out = self::n((new Formatter())->money(Money::of(120660, 'PLN'), 'pl'));
        $this->assertSame('1 206,60 zł', $out);
    }

    public function testUsdInEn(): void
    {
        // ICU for en_GB renders USD as "US$" (not "$", which is ambiguous in en_GB).
        $out = self::n((new Formatter())->money(Money::of(120660, 'USD'), 'en'));
        $this->assertStringContainsString('1,206.60', $out);
        $this->assertContains($out, ['US$1,206.60', '$1,206.60']);
    }

    public function testNegativeAndSmallAmounts(): void
    {
        $f = new Formatter();
        $this->assertSame('0,05 €', self::n($f->money(Money::of(5, 'EUR'), 'de')));
        $this->assertSame('-1.206,60 €', str_replace("\u{2212}", '-', self::n($f->money(Money::of(-120660, 'EUR'), 'de'))));
    }

    public function testMoneyUnknownLanguage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Formatter())->money(Money::of(1, 'EUR'), 'fr');
    }

    public function testDates(): void
    {
        $d = new \DateTimeImmutable('2026-11-18');
        $f = new Formatter();
        $this->assertSame('18.11.2026', $f->date($d, 'de'));
        $this->assertSame('18.11.2026', $f->date($d, 'pl'));
        $this->assertSame('18/11/2026', $f->date($d, 'en'));
    }

    public function testDateUnknownLanguage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Formatter())->date(new \DateTimeImmutable('2026-11-18'), 'fr');
    }

    public function testDecimalQuantities(): void
    {
        $f = new Formatter();
        $this->assertSame('2,5', $f->decimal(2500, 'de'));
        $this->assertSame('2,5', $f->decimal(2500, 'pl'));
        $this->assertSame('2.5', $f->decimal(2500, 'en'));
        $this->assertSame('2', $f->decimal(2000, 'de'));
        $this->assertSame('0,125', $f->decimal(125, 'pl'));
        $this->assertSame('1.234,5', self::n($f->decimal(1234500, 'de')));
    }

    public function testDecimalUnknownLanguage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Formatter())->decimal(1000, 'fr');
    }

    public function test_month_name_in_each_language_in_the_dates_own_timezone(): void
    {
        $f = new Formatter();
        $nov = new \DateTimeImmutable('2026-11-24 12:00', new \DateTimeZone('Europe/Berlin'));
        $this->assertSame(['November', 'listopad', 'November'], [$f->month($nov, 'de'), $f->month($nov, 'pl'), $f->month($nov, 'en')]);
        $this->assertSame(['November 2026', 'listopad 2026', 'November 2026'], [$f->month($nov, 'de', true), $f->month($nov, 'pl', true), $f->month($nov, 'en', true)]);
        $this->assertSame('Dezember', $f->month((new \DateTimeImmutable('2026-11-30 23:30', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin')), 'de'));
        $this->expectException(\InvalidArgumentException::class);
        $f->month($nov, 'fr');
    }
}
