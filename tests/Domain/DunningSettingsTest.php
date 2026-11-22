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
        yield 'base rate far too high' => [['dunning_base_rate_bp' => 3001] + $ok];
        yield 'base rate far too low' => [['dunning_base_rate_bp' => -1001] + $ok];
        yield 'b2b surcharge over 20 points' => [['dunning_b2b_surcharge_bp' => 2001] + $ok];
        yield 'b2c surcharge over 20 points' => [['dunning_b2c_surcharge_bp' => 2001] + $ok];
        yield 'absurd surcharge' => [['dunning_b2b_surcharge_bp' => PHP_INT_MAX] + $ok];
        yield 'flat fee over 1000' => [['dunning_flat_minor' => 100_001] + $ok];
        yield 'fee over 1000' => [['dunning_fee_minor' => 100_001] + $ok];
        yield 'absurd fee' => [['dunning_fee_minor' => PHP_INT_MAX] + $ok];
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

    public function testBoundsThemselvesAreAccepted(): void
    {
        $s = new DunningSettings([1, 2, 3, 365], DunningSettings::BASE_RATE_MAX_BP, DunningSettings::SURCHARGE_MAX_BP, DunningSettings::SURCHARGE_MAX_BP,
            DunningSettings::MAX_AMOUNT_MINOR, DunningSettings::MAX_AMOUNT_MINOR);
        self::assertSame(3000, $s->baseRateBp);
        $low = new DunningSettings([1, 2, 3, 4], DunningSettings::BASE_RATE_MIN_BP, 0, 0, 0, 0);
        self::assertSame(-1000, $low->baseRateBp);
    }

    public function testConstructorChecksTheSameBounds(): void
    {
        foreach ([[3001, 0, 0, 0, 0], [null, 2001, 0, 0, 0], [null, 0, 2001, 0, 0], [null, 0, 0, 100_001, 0], [null, 0, 0, 0, 100_001], [-1001, 0, 0, 0, 0]] as [$base, $b2b, $b2c, $flat, $fee]) {
            try {
                new DunningSettings([1, 2, 3, 4], $base, $b2b, $b2c, $flat, $fee);
                self::fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSettingsFormBoundsAreTheDomainBounds(): void
    {
        // the form accepts exactly what the domain object accepts: 30 % base rate yes, 31 % no; 20 points yes, 21 no; 1000 yes, 1000,01 no
        $db = \TestDb::fresh();
        $db->exec("INSERT INTO companies (name) VALUES ('X')");
        $db->exec("INSERT INTO users (email, password_hash) VALUES ('u@example.com', 'x')");
        $uid = $db->lastId();
        $cid = (int) $db->one('SELECT id FROM companies')['id'];
        $db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$cid, $uid, 'owner']);
        $repo = new \App\Repo\CompanySettingsRepo($db, \App\Tenant\CompanyContext::forUser($db, $uid, $cid), new \App\Core\Crypto(base64_encode(str_repeat('k', 32))), fn (): int => 1_700_000_000);
        $ok = ['dunning_d0' => '7', 'dunning_d1' => '21', 'dunning_d2' => '35', 'dunning_d3' => '49', 'dunning_base_rate' => '30', 'dunning_b2b' => '20', 'dunning_b2c' => '20', 'dunning_flat' => '1000', 'dunning_fee' => '1000'];
        $repo->saveDunning($ok);
        self::assertEquals(new DunningSettings([7, 21, 35, 49], 3000, 2000, 2000, 100_000, 100_000), DunningSettings::fromCompanyRow($db->one('SELECT * FROM companies WHERE id = ?', [$cid])));
        foreach (['dunning_base_rate' => '30,01', 'dunning_b2b' => '20,01', 'dunning_b2c' => '20,01', 'dunning_flat' => '1000,01', 'dunning_fee' => '1000,01', 'dunning_d3' => '366'] as $field => $bad) {
            try {
                $repo->saveDunning([$field => $bad] + $ok);
                self::fail("$field = $bad must be refused");
            } catch (\App\Domain\ValidationException $e) {
                self::assertArrayHasKey($field, $e->errors);
            }
        }
    }
}
