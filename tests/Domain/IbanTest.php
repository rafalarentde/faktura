<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Iban;
use App\Domain\Percent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IbanTest extends TestCase
{
    #[DataProvider('valid')]
    public function test_valid_ibans(string $iban): void
    {
        $this->assertTrue(Iban::isValid($iban), $iban);
    }

    /** @return array<string, array{string}> */
    public static function valid(): array
    {
        return [
            'German' => ['DE89 3704 0044 0532 0130 00'],
            'German compact lower case' => ['de89370400440532013000'],
            'Polish' => ['PL61 1090 1014 0000 0712 1981 2874'],
            'French' => ['FR14 2004 1010 0505 0001 3M02 606'],
            'British (letters in BBAN)' => ['GB82 WEST 1234 5698 7654 32'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_ibans(string $iban): void
    {
        $this->assertFalse(Iban::isValid($iban), $iban);
    }

    /** @return array<string, array{string}> */
    public static function invalid(): array
    {
        return [
            'corrupted check digits' => ['DE88 3704 0044 0532 0130 00'],
            'one digit changed' => ['DE89 3704 0044 0532 0130 01'],
            'transposed' => ['PL61 1090 1014 0000 0712 1981 2847'],
            'too short for DE' => ['DE89 3704 0044 0532 0130'],
            'too long for DE' => ['DE89 3704 0044 0532 0130 000'],
            'garbage' => ['not an iban'],
            'empty' => [''],
            'special chars' => ['DE89-3704-0044-0532-0130-00!'],
        ];
    }

    public function test_normalize(): void
    {
        $this->assertSame('DE89370400440532013000', Iban::normalize(" de89 3704\u{00A0}0044 0532 0130 00 "));
    }

    public function test_percent_parsing_and_format(): void
    {
        $this->assertSame(127, Percent::parseBp('1,27'));
        $this->assertSame(127, Percent::parseBp('1.27'));
        $this->assertSame(900, Percent::parseBp('9'));
        $this->assertSame(150, Percent::parseBp(' 1,5 % '));
        $this->assertSame(0, Percent::parseBp('0'));
        foreach (['1,234', '', '-1', 'abc', '1,', ',5', '1.2.3', '1 000', '1e2'] as $bad) {
            try {
                Percent::parseBp($bad);
                $this->fail("Accepted: {$bad}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('1,27', Percent::format(127, 'de'));
        $this->assertSame('1.27', Percent::format(127, 'en'));
        $this->assertSame('9', Percent::format(900, 'pl'));
        $this->assertSame('1,5', Percent::format(150, 'de'));
        $this->assertSame('0,05', Percent::format(5, 'de'));
        $this->assertSame('0', Percent::format(0, 'de'));
    }
}
