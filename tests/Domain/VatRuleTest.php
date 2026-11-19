<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\VatRule;
use App\Vies\ViesStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, bool, bool, string, ?string}>
     */
    public static function table(): iterable
    {
        $note = 'invoice.reverse_charge_note';
        // row 1
        yield 'DE company valid vies' => [['country' => 'DE', 'is_company' => true, 'vat_id' => 'DE123456789', 'vies' => ViesStatus::Valid], false, false, 'domestic', null];
        yield 'DE company no id' => [['country' => 'DE', 'is_company' => true, 'vat_id' => null, 'vies' => null], false, false, 'domestic', null];
        yield 'DE private' => [['country' => 'DE', 'is_company' => false, 'vat_id' => null, 'vies' => null], false, false, 'domestic', null];
        // row 2
        yield 'PL company valid' => [['country' => 'PL', 'is_company' => true, 'vat_id' => 'PL1234567890', 'vies' => ViesStatus::Valid], true, false, 'reverse_charge', $note];
        yield 'GR company valid' => [['country' => 'GR', 'is_company' => true, 'vat_id' => 'EL123456789', 'vies' => ViesStatus::Valid], true, false, 'reverse_charge', $note];
        yield 'lower-case country and padded ids' => [['country' => ' pl ', 'is_company' => true, 'vat_id' => '  PL1234567890 ', 'vies' => ViesStatus::Valid], true, false, 'reverse_charge', $note];
        // row 3
        yield 'PL company invalid' => [['country' => 'PL', 'is_company' => true, 'vat_id' => 'PL1', 'vies' => ViesStatus::Invalid], false, true, 'vies_not_confirmed', null];
        yield 'PL company unavailable' => [['country' => 'PL', 'is_company' => true, 'vat_id' => 'PL1', 'vies' => ViesStatus::Unavailable], false, true, 'vies_not_confirmed', null];
        yield 'PL company never checked' => [['country' => 'PL', 'is_company' => true, 'vat_id' => 'PL1', 'vies' => null], false, true, 'vies_not_confirmed', null];
        yield 'missing vies key' => [['country' => 'FR', 'is_company' => true, 'vat_id' => 'FR1'], false, true, 'vies_not_confirmed', null];
        // row 4
        yield 'PL company no id' => [['country' => 'PL', 'is_company' => true, 'vat_id' => null, 'vies' => null], false, false, 'no_vat_id', null];
        yield 'PL company empty id' => [['country' => 'PL', 'is_company' => true, 'vat_id' => '', 'vies' => ViesStatus::Valid], false, false, 'no_vat_id', null];
        yield 'PL company blank id, Valid is not trusted' => [['country' => 'PL', 'is_company' => true, 'vat_id' => '   ', 'vies' => ViesStatus::Valid], false, false, 'no_vat_id', null];
        // row 5
        yield 'PL private' => [['country' => 'PL', 'is_company' => false, 'vat_id' => null, 'vies' => null], false, false, 'private_customer', null];
        yield 'PL private with id and valid vies' => [['country' => 'PL', 'is_company' => false, 'vat_id' => 'PL1', 'vies' => ViesStatus::Valid], false, false, 'private_customer', null];
        // row 6
        yield 'US' => [['country' => 'US', 'is_company' => true, 'vat_id' => 'X', 'vies' => ViesStatus::Valid], false, false, 'non_eu', null];
        yield 'CH' => [['country' => 'CH', 'is_company' => true, 'vat_id' => null, 'vies' => null], false, false, 'non_eu', null];
        yield 'GB' => [['country' => 'gb', 'is_company' => false, 'vat_id' => null, 'vies' => null], false, false, 'non_eu', null];
        yield 'NO' => [['country' => 'NO', 'is_company' => true, 'vat_id' => 'NO1', 'vies' => ViesStatus::Valid], false, false, 'non_eu', null];
    }

    /** @param array<string, mixed> $buyer */
    #[DataProvider('table')]
    public function testDecisionTable(array $buyer, bool $rc, bool $confirm, string $reason, ?string $note): void
    {
        $d = VatRule::decide($buyer);
        self::assertSame($rc, $d->reverseCharge);
        self::assertSame($confirm, $d->needsConfirmation);
        self::assertSame($reason, $d->reason);
        self::assertSame($note, $d->noteKey);
    }

    /** @return iterable<string, array{string}> */
    public static function badCountries(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['  '];
        yield 'one letter' => ['D'];
        yield 'three letters' => ['DEU'];
        yield 'digits' => ['1A'];
    }

    #[DataProvider('badCountries')]
    public function testBadCountryRejected(string $country): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VatRule::decide(['country' => $country, 'is_company' => true, 'vat_id' => null, 'vies' => null]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badBuyers(): iterable
    {
        $ok = ['country' => 'PL', 'is_company' => true, 'vat_id' => 'PL1', 'vies' => ViesStatus::Valid];
        yield 'is_company int 1' => [['is_company' => 1] + $ok];
        yield 'is_company string 1' => [['is_company' => '1'] + $ok];
        yield 'is_company missing' => [array_diff_key($ok, ['is_company' => 1])];
        yield 'is_company null' => [['is_company' => null] + $ok];
        yield 'vies string' => [['vies' => 'valid'] + $ok];
        yield 'vies int' => [['vies' => 1] + $ok];
        yield 'vat_id array' => [['vat_id' => ['PL1']] + $ok];
        yield 'vat_id int' => [['vat_id' => 123] + $ok];
        yield 'country int' => [['country' => 1] + $ok];
        yield 'country array' => [['country' => ['PL']] + $ok];
        yield 'country null' => [['country' => null] + $ok];
        yield 'country UK' => [['country' => 'UK'] + $ok];
        yield 'country XI' => [['country' => 'XI'] + $ok];
        yield 'country ZZ' => [['country' => 'ZZ'] + $ok];
    }

    /** @param array<string, mixed> $buyer */
    #[DataProvider('badBuyers')]
    public function testStrictInputRejected(array $buyer): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VatRule::decide($buyer);
    }

    public function testMissingViesKeyMeansNeverChecked(): void
    {
        $d = VatRule::decide(['country' => 'PL', 'is_company' => true, 'vat_id' => 'PL1']);
        self::assertSame('vies_not_confirmed', $d->reason);
        $d = VatRule::decide(['country' => 'PL', 'is_company' => false]);
        self::assertSame('private_customer', $d->reason);
    }

    public function testElMapsToGreece(): void
    {
        $d = VatRule::decide(['country' => ' el ', 'is_company' => true, 'vat_id' => 'EL1', 'vies' => ViesStatus::Valid]);
        self::assertTrue($d->reverseCharge);
    }

    public function testValidNonEuCountriesStayValid(): void
    {
        foreach (['GB', 'CH', 'NO', 'US'] as $c) {
            self::assertSame('non_eu', VatRule::decide(['country' => $c, 'is_company' => true])->reason);
        }
    }

    /** @param array<string, mixed> $extra */
    private static function row(array $extra): array
    {
        return $extra + ['country' => 'PL', 'is_company' => 1, 'vat_id' => 'PL1234567890', 'vies_status' => 'valid'];
    }

    public function testRowAdapterReverseCharge(): void
    {
        $d = VatRule::decide(VatRule::buyerFromCustomerRow(self::row([])));
        self::assertTrue($d->reverseCharge);
        $d = VatRule::decide(VatRule::buyerFromCustomerRow(self::row(['is_company' => '1'])));
        self::assertTrue($d->reverseCharge);
    }

    public function testRowAdapterPrivateAndUnconfirmed(): void
    {
        self::assertSame('private_customer', VatRule::decide(VatRule::buyerFromCustomerRow(self::row(['is_company' => 0])))->reason);
        self::assertSame('private_customer', VatRule::decide(VatRule::buyerFromCustomerRow(self::row(['is_company' => '0'])))->reason);
        foreach (['unavailable', 'invalid', null] as $status) {
            $d = VatRule::decide(VatRule::buyerFromCustomerRow(self::row(['vies_status' => $status])));
            self::assertTrue($d->needsConfirmation);
            self::assertFalse($d->reverseCharge);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function garbageRows(): iterable
    {
        yield 'is_company 2' => [self::row(['is_company' => 2])];
        yield 'is_company yes' => [self::row(['is_company' => 'yes'])];
        yield 'is_company null' => [self::row(['is_company' => null])];
        yield 'is_company missing' => [array_diff_key(self::row([]), ['is_company' => 1])];
        yield 'is_company float' => [self::row(['is_company' => 1.0])];
        yield 'vies unknown' => [self::row(['vies_status' => 'maybe'])];
        yield 'vies upper' => [self::row(['vies_status' => 'VALID'])];
        yield 'vies int' => [self::row(['vies_status' => 1])];
        yield 'vat_id int' => [self::row(['vat_id' => 5])];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('garbageRows')]
    public function testRowAdapterRejectsGarbage(array $row): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VatRule::buyerFromCustomerRow($row);
    }

    public function testRowAdapterBadCountryRejectedByDecide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VatRule::decide(VatRule::buyerFromCustomerRow(self::row(['country' => ''])));
    }

    public function testMissingCountryRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VatRule::decide(['is_company' => true, 'vat_id' => null, 'vies' => null]);
    }
}
