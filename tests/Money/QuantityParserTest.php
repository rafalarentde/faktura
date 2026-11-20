<?php
declare(strict_types=1);

namespace Tests\Money;

use App\Money\Formatter;
use App\Money\QuantityParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Quantities typed in the invoice editor -> thousandths (qty_milli). Same separators and
 * strictness as AmountParser, but up to 3 decimals, > 0 and at most 999,999.999:
 *  - de/pl: "," decimal, "." group; en: "." decimal, "," group;
 *  - the language's own group separator in proper groups is a thousands separator ("1.234" de =
 *    1234, "1,234" en = 1234), so "1.234" in de is NEVER 1.234;
 *  - the foreign separator alone, once, not in proper grouping, is the decimal point ("2.5" de);
 *  - 4 decimals, letters, signs, zero are rejected.
 */
final class QuantityParserTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function valid(): iterable
    {
        foreach (['de', 'pl', 'en'] as $l) {
            yield "$l integer" => ['2', $l, 2000];
            yield "$l trimmed" => [' 5 ', $l, 5000];
            yield "$l max" => ['999 999', $l, 999_999_000];
            yield "$l smallest" => ['0' . ($l === 'en' ? '.' : ',') . '001', $l, 1];
            yield "$l leading zeros" => ['007', $l, 7000];
            yield "$l nbsp group" => ["1\u{00A0}234", $l, 1_234_000];
        }
        foreach (['de', 'pl'] as $l) {
            yield "$l 2,5" => ['2,5', $l, 2500];
            yield "$l 1,234 (three decimals)" => ['1,234', $l, 1234];
            yield "$l 0,125" => ['0,125', $l, 125];
            yield "$l 2.5 (foreign point)" => ['2.5', $l, 2500];
            yield "$l 1.25 (foreign point)" => ['1.25', $l, 1250];
            yield "$l 1.234 (group)" => ['1.234', $l, 1_234_000];
            yield "$l 1.234,5" => ['1.234,5', $l, 1_234_500];
            yield "$l 999.999,999" => ['999.999,999', $l, 999_999_999];
            yield "$l 1 234,5" => ['1 234,5', $l, 1_234_500];
        }
        yield 'en 2.5' => ['2.5', 'en', 2500];
        yield 'en 1.234 (three decimals)' => ['1.234', 'en', 1234];
        yield 'en 1,234 (group)' => ['1,234', 'en', 1_234_000];
        yield 'en 2,5 (foreign comma)' => ['2,5', 'en', 2500];
        yield 'en 1,234.5' => ['1,234.5', 'en', 1_234_500];
        yield 'en 999,999.999' => ['999,999.999', 'en', 999_999_999];
    }

    #[DataProvider('valid')]
    public function test_parses(string $input, string $lang, int $milli): void
    {
        $this->assertSame($milli, QuantityParser::parse($input, $lang));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        foreach (['de', 'pl', 'en'] as $l) {
            $d = $l === 'en' ? '.' : ',';
            yield "$l empty" => ['', $l];
            yield "$l blank" => ['  ', $l];
            yield "$l zero" => ['0', $l];
            yield "$l zero decimals" => ['0' . $d . '000', $l];
            yield "$l four decimals" => ['1' . $d . '2345', $l];
            yield "$l letters" => ['2x', $l];
            yield "$l word" => ['zwei', $l];
            yield "$l negative" => ['-1', $l];
            yield "$l plus" => ['+1', $l];
            yield "$l too big" => ['1000000', $l];
            yield "$l too big decimals" => ['999999' . $d . '9991', $l];
            yield "$l exponent" => ['1e3', $l];
            yield "$l two decimal points" => ['1' . $d . '2' . $d . '3', $l];
            yield "$l trailing separator" => ['2' . $d, $l];
            yield "$l leading separator" => [$d . '5', $l];
            yield "$l NUL" => ["2\0", $l];
            yield "$l bad grouping" => ['12 34', $l];
            yield "$l unicode digits" => ['٢', $l];
        }
        yield 'de 1,234,5' => ['1,234,5', 'de'];
        yield 'de 1.2345 (foreign, four decimals)' => ['1.2345', 'de'];
        yield 'en 1.234.5' => ['1.234.5', 'en'];
        yield 'en 1,2345' => ['1,2345', 'en'];
        yield 'unsupported language' => ['2', 'fr'];
    }

    #[DataProvider('invalid')]
    public function test_rejects(string $input, string $lang): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QuantityParser::parse($input, $lang);
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function formatted(): iterable
    {
        yield 'de 2' => [2000, 'de', '2'];
        yield 'de 2,5' => [2500, 'de', '2,5'];
        yield 'de 0,125' => [125, 'de', '0,125'];
        yield 'en 2.5' => [2500, 'en', '2.5'];
        yield 'de group' => [1_234_500, 'de', '1.234,5'];
        yield 'en group' => [1_234_500, 'en', '1,234.5'];
        yield 'pl 2,5' => [2500, 'pl', '2,5'];
    }

    #[DataProvider('formatted')]
    public function test_formatter_quantity(int $milli, string $lang, string $expected): void
    {
        $this->assertSame($expected, (new Formatter())->quantity($milli, $lang));
    }

    public function test_formatted_quantity_parses_back(): void
    {
        $fmt = new Formatter();
        foreach (['de', 'pl', 'en'] as $l) {
            foreach ([1, 125, 2500, 1_234_500, 999_999_999] as $milli) {
                $this->assertSame($milli, QuantityParser::parse($fmt->quantity($milli, $l), $l), "$l $milli");
            }
        }
    }
}
