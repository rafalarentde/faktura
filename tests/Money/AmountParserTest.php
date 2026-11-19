<?php
declare(strict_types=1);

namespace Tests\Money;

use App\Money\AmountParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What each ambiguous input means, per language (de/pl: "," decimal, "." group; en: "." decimal,
 * "," group):
 *  - A group separator is only valid in proper groups: 1-3 digits, then groups of exactly 3.
 *  - With the language's own decimal separator at most 2 decimals are allowed. "1,234" in de/pl
 *    is 1.234 (three decimals) and is REJECTED, never read as 1234.
 *  - The language's group separator alone in proper grouping is a thousands separator
 *    ("1.234" in de = 1234, "1,234" in en = 1234).
 *  - The foreign separator alone, once, followed by 1-2 digits is read as the decimal point
 *    ("450.50" in de = 450.50, "450,50" in en = 450.50), because people type their own habit.
 *  - Spaces (normal, NBSP, narrow NBSP, thin) are ignored anywhere ("1 234,56").
 */
final class AmountParserTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function valid(): iterable
    {
        foreach (['de', 'pl', 'en'] as $l) {
            yield "$l plain integer" => ['450', $l, 45000];
            yield "$l zero" => ['0', $l, 0];
            yield "$l zero decimals" => ['0,00', $l, 0];
            yield "$l leading zero" => ['0,05', $l, 5];
            yield "$l max" => ['999999999.99', $l, 99999999999];
            yield "$l trimmed" => ['  12  ', $l, 1200];
            yield "$l 00012,50" => ['00012,50', $l, 1250];
            yield "$l 1 234 567,89 spaces" => ['1 234 567,89', $l, 123456789];
            yield "$l 1 234 plain" => ['1 234', $l, 123400];
            yield "$l nbsp grouping" => ["1\u{00A0}234,56", $l, 123456];
            yield "$l trailing nbsp" => ["12\u{00A0}", $l, 1200];
        }
        yield 'en 1,5' => ['1,5', 'en', 150];
        yield 'de 1,5' => ['1,5', 'de', 150];
        yield 'de 1.500 (point = thousands)' => ['1.500', 'de', 150000];
        // de / pl
        foreach (['de', 'pl'] as $l) {
            yield "$l 450,5" => ['450,5', $l, 45050];
            yield "$l 450,50" => ['450,50', $l, 45050];
            yield "$l 450.50 (foreign point = decimal)" => ['450.50', $l, 45050];
            yield "$l 12.5 (foreign point = decimal)" => ['12.5', $l, 1250];
            yield "$l 1.234,56" => ['1.234,56', $l, 123456];
            yield "$l 1.234 (point = thousands)" => ['1.234', $l, 123400];
            yield "$l 1.234.567" => ['1.234.567', $l, 123456700];
            yield "$l 1 234,56" => ['1 234,56', $l, 123456];
            yield "$l nbsp" => ["1\u{00A0}234,56", $l, 123456];
            yield "$l narrow nbsp" => ["1\u{202F}234,56", $l, 123456];
            yield "$l 999.999.999,99" => ['999.999.999,99', $l, 99999999999];
        }
        // en
        yield 'en 450.5' => ['450.5', 'en', 45050];
        yield 'en 1,234.56' => ['1,234.56', 'en', 123456];
        yield 'en 1,234 (comma = thousands)' => ['1,234', 'en', 123400];
        yield 'en 450,50 (foreign comma = decimal)' => ['450,50', 'en', 45050];
        yield 'en 1,234,567.8' => ['1,234,567.8', 'en', 123456780];
        yield 'en 1 234.56' => ['1 234.56', 'en', 123456];
        yield 'en nbsp' => ["1\u{00A0}234.56", 'en', 123456];
    }

    #[DataProvider('valid')]
    public function test_parses(string $input, string $lang, int $minor): void
    {
        $this->assertSame($minor, AmountParser::parse($input, $lang));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        foreach (['de', 'pl', 'en'] as $l) {
            yield "$l empty" => ['', $l];
            yield "$l blank" => ['   ', $l];
            yield "$l negative" => ['-5', $l];
            yield "$l plus" => ['+5', $l];
            yield "$l letters" => ['abc', $l];
            yield "$l unit suffix" => ['12 EUR', $l];
            yield "$l mixed letters" => ['1a2', $l];
            yield "$l above max" => ['1000000000', $l];
            yield "$l above max cents" => ['999999999,999', $l];
            yield "$l only separator" => [',', $l];
            yield "$l leading separator" => [',5', $l];
            yield "$l trailing separator" => ['5,', $l];
            yield "$l two decimal points" => ['1,2,3,4', $l];
            yield "$l exponent" => ['1e3', $l];
            yield "$l unicode digits" => ["\u{0661}\u{0662}", $l];
            yield "$l trailing NUL" => ["5\0", $l];
            yield "$l leading NUL" => ["\0 5", $l];
            yield "$l hex" => ['0x10', $l];
            yield "$l euro suffix" => ['5 €', $l];
            yield "$l euro prefix" => ['€5', $l];
            yield "$l minus zero" => ['-0', $l];
            yield "$l 1 2 3" => ['1 2 3', $l];
            yield "$l 12 34" => ['12 34', $l];
            yield "$l double space" => ['1  234', $l];
            yield "$l 1 2" => ['1 2', $l];
            yield "$l space before decimals" => ['1 234 ,56', $l];
            yield "$l inner newline" => ["1\n2", $l];
        }
        yield 'de 1,234 = three decimals' => ['1,234', 'de'];
        yield 'pl 1,234 = three decimals' => ['1,234', 'pl'];
        yield 'en 1.500 = three decimals' => ['1.500', 'en'];
        yield 'en 1.234 = three decimals' => ['1.234', 'en'];
        yield 'de 12,345' => ['12,345', 'de'];
        yield 'de bad grouping' => ['12.34.567,00', 'de'];
        yield 'de group after decimal' => ['1,234.56', 'de'];
        yield 'en group after decimal' => ['1.234,56', 'en'];
        yield 'en bad grouping' => ['1,23,456', 'en'];
        yield 'de 1.234.5 (mixed)' => ['1.234.5', 'de'];
        yield 'unsupported language' => ['1', 'fr'];
    }

    #[DataProvider('invalid')]
    public function test_rejects(string $input, string $lang): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AmountParser::parse($input, $lang);
    }
}
