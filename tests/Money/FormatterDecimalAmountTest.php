<?php
declare(strict_types=1);

namespace Tests\Money;

use App\Money\AmountParser;
use App\Money\Formatter;
use PHPUnit\Framework\TestCase;

final class FormatterDecimalAmountTest extends TestCase
{
    public function test_formats_per_language(): void
    {
        $f = new Formatter();
        $this->assertSame('450,00', $f->decimalAmount(45000, 'de'));
        $this->assertSame('450,00', $f->decimalAmount(45000, 'pl'));
        $this->assertSame('450.00', $f->decimalAmount(45000, 'en'));
        $this->assertSame('0,05', $f->decimalAmount(5, 'de'));
        $this->assertSame('0.00', $f->decimalAmount(0, 'en'));
        $this->assertSame('1234567,89', $f->decimalAmount(123456789, 'de'), 'no thousands separator');
        $this->assertSame('999999999.99', $f->decimalAmount(99999999999, 'en'));
    }

    public function test_negative_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Formatter())->decimalAmount(-1, 'de');
    }

    public function test_unsupported_language_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Formatter())->decimalAmount(1, 'fr');
    }

    public function test_round_trip(): void
    {
        $f = new Formatter();
        $values = [0, 1, 5, 9, 10, 99, 100, 101, 1050, 45000, 99999, 100000, 123456, 1000000, 99999999, 123456789, 99999999999];
        for ($i = 0; $i < 200; $i++) {
            $values[] = random_int(0, 99999999999);
        }
        foreach (['de', 'pl', 'en'] as $lang) {
            foreach ($values as $x) {
                $this->assertSame($x, AmountParser::parse($f->decimalAmount($x, $lang), $lang), "$lang $x");
            }
        }
    }
}
