<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\VatId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatIdTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?string}> */
    public static function cases(): iterable
    {
        yield 'plain with prefix' => ['PL', 'PL1234567890', 'PL1234567890'];
        yield 'spaces dots dashes' => ['PL', ' pl 123-456.78 90 ', 'PL1234567890'];
        yield 'lower case' => ['DE', 'de123456789', 'DE123456789'];
        yield 'no prefix gets one' => ['DE', '123 456 789', 'DE123456789'];
        yield 'duplicated prefix' => ['DE', 'DEDE123456789', 'DE123456789'];
        yield 'greece iso GR uses EL' => ['GR', '123456789', 'EL123456789'];
        yield 'greece typed EL' => ['GR', 'EL 123456789', 'EL123456789'];
        yield 'greece typed GR' => ['GR', 'GR123456789', 'EL123456789'];
        yield 'empty' => ['DE', '', null];
        yield 'only separators' => ['DE', ' .- ', null];
        yield 'only prefix' => ['DE', 'DE', null];
        yield 'AT with U' => ['AT', 'ATU12345678', 'ATU12345678'];
    }

    #[DataProvider('cases')]
    public function test_normalize(string $country, string $raw, ?string $expected): void
    {
        $this->assertSame($expected, VatId::normalize($country, $raw));
    }

    public function test_prefix_maps_greece_to_el(): void
    {
        $this->assertSame('EL', VatId::prefix('GR'));
        $this->assertSame('DE', VatId::prefix('DE'));
    }

    public function test_number_without_prefix(): void
    {
        $this->assertSame('123456789', VatId::number('GR', 'EL123456789'));
        $this->assertSame('1234567890', VatId::number('PL', 'PL1234567890'));
    }
}
