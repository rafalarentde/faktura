<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\DunningSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DunningSettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $s = DunningSettings::defaults();
        self::assertSame([7, 21, 35, 49], $s->thresholds);
        self::assertNull($s->baseRateBp);
        self::assertSame(900, $s->b2bSurchargeBp);
        self::assertSame(500, $s->b2cSurchargeBp);
        self::assertSame(4000, $s->flatMinor);
        self::assertSame(0, $s->feeMinor);
    }

    public function testMigrationDefaultsEqualDefaults(): void
    {
        $sql = (string) file_get_contents(__DIR__ . '/../../migrations/001_base.sql');
        $row = [];
        foreach (['d0', 'd1', 'd2', 'd3', 'b2b_surcharge_bp', 'b2c_surcharge_bp', 'flat_minor', 'fee_minor'] as $c) {
            self::assertSame(1, preg_match('/dunning_' . $c . ' \w+ NOT NULL DEFAULT (\d+)/', $sql, $m), $c);
            $row['dunning_' . $c] = (int) $m[1];
        }
        self::assertSame(1, preg_match('/dunning_base_rate_bp INTEGER NULL,/', $sql));
        $row['dunning_base_rate_bp'] = null;
        self::assertEquals(DunningSettings::defaults(), DunningSettings::fromCompanyRow($row));
    }

    public function testFromRowWithBaseRateAndNumericStrings(): void
    {
        $s = DunningSettings::fromCompanyRow([
            'dunning_d0' => '3', 'dunning_d1' => 10, 'dunning_d2' => 20, 'dunning_d3' => 30,
            'dunning_base_rate_bp' => '-50', 'dunning_b2b_surcharge_bp' => 900,
            'dunning_b2c_surcharge_bp' => 500, 'dunning_flat_minor' => 5000, 'dunning_fee_minor' => 250,
        ]);
        self::assertSame([3, 10, 20, 30], $s->thresholds);
        self::assertSame(-50, $s->baseRateBp);
        self::assertSame(5000, $s->flatMinor);
        self::assertSame(250, $s->feeMinor);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badRows(): iterable
    {
        $ok = ['dunning_d0' => 7, 'dunning_d1' => 21, 'dunning_d2' => 35, 'dunning_d3' => 49,
            'dunning_base_rate_bp' => null, 'dunning_b2b_surcharge_bp' => 900,
            'dunning_b2c_surcharge_bp' => 500, 'dunning_flat_minor' => 4000, 'dunning_fee_minor' => 0];
        yield 'descending' => [['dunning_d1' => 5] + $ok];
        yield 'equal' => [['dunning_d1' => 7] + $ok];
        yield 'zero' => [['dunning_d0' => 0] + $ok];
        yield 'negative' => [['dunning_d0' => -1] + $ok];
        yield '366' => [['dunning_d3' => 366] + $ok];
        yield 'float' => [['dunning_d0' => 7.0] + $ok];
        yield 'text' => [['dunning_d0' => 'abc'] + $ok];
        yield 'empty string' => [['dunning_d0' => ''] + $ok];
        yield 'missing' => [array_diff_key($ok, ['dunning_d2' => 1])];
        yield 'null threshold' => [['dunning_d2' => null] + $ok];
        yield 'negative surcharge' => [['dunning_b2b_surcharge_bp' => -1] + $ok];
        yield 'negative flat' => [['dunning_flat_minor' => -1] + $ok];
        yield 'negative fee' => [['dunning_fee_minor' => -1] + $ok];
        yield 'bad base rate' => [['dunning_base_rate_bp' => 'x'] + $ok];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('badRows')]
    public function testInvalidRowsThrow(array $row): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DunningSettings::fromCompanyRow($row);
    }

    public function testBoundaryThresholdsAccepted(): void
    {
        $s = new DunningSettings([1, 2, 364, 365], null, 0, 0, 0, 0);
        self::assertSame([1, 2, 364, 365], $s->thresholds);
    }

    public function testWrongThresholdCountThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DunningSettings([1, 2, 3], null, 900, 500, 4000, 0);
    }
}
