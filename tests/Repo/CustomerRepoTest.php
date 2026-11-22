<?php
declare(strict_types=1);

namespace Tests\Repo;

use App\Core\Db;
use App\Domain\ValidationException;
use App\Repo\CustomerRepo;
use App\Tenant\CompanyContext;
use App\Vies\ViesStatus;
use PHPUnit\Framework\TestCase;

final class CustomerRepoTest extends TestCase
{
    private Db $db;
    private int $companyA;
    private int $companyB;
    private CustomerRepo $repo;
    private CustomerRepo $repoB;

    protected function setUp(): void
    {
        $this->db = \TestDb::fresh();
        $this->companyA = $this->company('A GmbH', 'pl', 30);
        $this->companyB = $this->company('B GmbH', 'de', 14);
        $this->repo = $this->repoFor($this->companyA, 'a@example.pl');
        $this->repoB = $this->repoFor($this->companyB, 'b@example.pl');
    }

    private function company(string $name, string $lang, int $days): int
    {
        $this->db->exec('INSERT INTO companies (name, default_lang, default_payment_days) VALUES (?, ?, ?)', [$name, $lang, $days]);
        return $this->db->lastId();
    }

    private function repoFor(int $companyId, string $email): CustomerRepo
    {
        $this->db->exec('INSERT INTO users (email, password_hash) VALUES (?, ?)', [$email, 'x']);
        $uid = $this->db->lastId();
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$companyId, $uid, 'owner']);
        return new CustomerRepo($this->db, CompanyContext::forUser($this->db, $uid, $companyId));
    }

    /** @return array<string, string> */
    private function errors(array $data): array
    {
        try {
            $this->repo->save($data);
        } catch (ValidationException $e) {
            return $e->errors;
        }
        $this->fail('Expected ValidationException');
    }

    public function test_save_applies_defaults_from_company(): void
    {
        $id = $this->repo->save(['name' => '  Müller GmbH ']);
        $row = $this->repo->find($id);
        $this->assertSame('Müller GmbH', $row['name']);
        $this->assertSame('DE', $row['country']);
        $this->assertSame(1, (int) $row['is_company']);
        $this->assertSame('pl', $row['default_lang']);
        $this->assertSame(30, (int) $row['payment_days']);
        $this->assertSame('EUR', $row['default_currency']);
        $this->assertNull($row['vat_id']);
        $this->assertNull($row['vies_status']);
        $this->assertSame($this->companyA, (int) $row['company_id']);
    }

    public function test_save_full_and_update(): void
    {
        $id = $this->repo->save([
            'name' => 'Acme', 'address' => "Ul. Długa 1\r\n00-001 Warszawa", 'country' => 'PL', 'is_company' => '1',
            'vat_id' => 'pl 123-456-78-90', 'email' => 'x@example.pl', 'default_lang' => 'en', 'default_currency' => 'PLN',
            'payment_days' => '7', 'notes' => 'n',
        ]);
        $row = $this->repo->find($id);
        $this->assertSame("Ul. Długa 1\n00-001 Warszawa", $row['address']);
        $this->assertSame('PL1234567890', $row['vat_id']);
        $this->assertSame('PLN', $row['default_currency']);
        $this->assertSame(7, (int) $row['payment_days']);
        $this->assertSame($id, $this->repo->save(['name' => 'Acme 2', 'country' => 'PL', 'vat_id' => 'PL1234567890'], $id));
        $row = $this->repo->find($id);
        $this->assertSame('Acme 2', $row['name']);
        // fields not supplied keep their stored value on update
        $this->assertSame('x@example.pl', $row['email']);
        $this->assertSame('PLN', $row['default_currency']);
        $this->assertSame(7, (int) $row['payment_days']);
    }

    public function test_validation_matrix(): void
    {
        $this->assertSame(['name' => 'customer.err_name'], $this->errors(['name' => '   ']));
        $this->assertSame(['name' => 'customer.err_name'], $this->errors(['name' => str_repeat('a', 191)]));
        $this->assertSame(['country' => 'customer.err_country'], $this->errors(['name' => 'X', 'country' => 'XX']));
        $this->assertSame(['country' => 'customer.err_country'], $this->errors(['name' => 'X', 'country' => 'de']));
        $this->assertSame(['email' => 'customer.err_email'], $this->errors(['name' => 'X', 'email' => 'not-an-email']));
        $this->assertSame(['default_lang' => 'customer.err_lang'], $this->errors(['name' => 'X', 'default_lang' => 'fr']));
        $this->assertSame(['default_currency' => 'customer.err_currency'], $this->errors(['name' => 'X', 'default_currency' => 'GBP']));
        $this->assertSame(['payment_days' => 'customer.err_payment_days'], $this->errors(['name' => 'X', 'payment_days' => '366']));
        $this->assertSame(['payment_days' => 'customer.err_payment_days'], $this->errors(['name' => 'X', 'payment_days' => '-1']));
        $this->assertSame(['payment_days' => 'customer.err_payment_days'], $this->errors(['name' => 'X', 'payment_days' => 'abc']));
        $this->assertSame(['vat_id' => 'customer.err_vat_id'], $this->errors(['name' => 'X', 'country' => 'DE', 'vat_id' => 'DE12<3>']));
        $all = $this->errors(['name' => '', 'country' => 'ZZ', 'email' => 'x']);
        $this->assertSame(['name', 'country', 'email'], array_keys($all));
        $this->assertSame(0, $this->repo->count(), 'nothing is written on failure');
    }

    public function test_boundaries_are_accepted(): void
    {
        $id = $this->repo->save(['name' => str_repeat('ä', 190), 'payment_days' => '0']);
        $this->assertSame(0, (int) $this->repo->find($id)['payment_days']);
        $id2 = $this->repo->save(['name' => 'Y', 'payment_days' => '365', 'is_company' => '0', 'country' => 'GR', 'vat_id' => '123456789']);
        $this->assertSame(0, (int) $this->repo->find($id2)['is_company']);
        $this->assertSame('EL123456789', $this->repo->find($id2)['vat_id']);
    }

    public function test_name_with_html_is_stored_raw(): void
    {
        $id = $this->repo->save(['name' => '<script>alert(1)</script> & Co']);
        $this->assertSame('<script>alert(1)</script> & Co', $this->repo->find($id)['name']);
    }

    public function test_list_filters_case_insensitively_incl_non_ascii(): void
    {
        $this->repo->save(['name' => 'Müller GmbH', 'email' => 'info@mueller.de']);
        $this->repo->save(['name' => 'Łódź Sp. z o.o.', 'country' => 'PL', 'vat_id' => 'PL1234567890']);
        $this->repo->save(['name' => 'ŻABKA Polska', 'country' => 'PL']);
        $this->repo->save(['name' => 'Acme Ltd']);

        $names = fn (string $q) => array_column($this->repo->list($q), 'name');
        // order is by name in the database's collation (bytewise on SQLite), so compare as a set
        $this->assertEqualsCanonicalizing(['Acme Ltd', 'Łódź Sp. z o.o.', 'Müller GmbH', 'ŻABKA Polska'], $names(''));
        $this->assertSame('Acme Ltd', $names('')[0]);
        $this->assertSame(['Acme Ltd'], $names('acme'));
        $this->assertSame(['Acme Ltd'], $names('ACME'));
        $this->assertSame(['Müller GmbH'], $names('müller'));
        $this->assertSame(['Müller GmbH'], $names('MÜLLER'));
        $this->assertSame(['Müller GmbH'], $names('Müller'));
        $this->assertSame(['Łódź Sp. z o.o.'], $names('łódź'));
        $this->assertSame(['Łódź Sp. z o.o.'], $names('ŁÓDŹ'));
        $this->assertSame(['ŻABKA Polska'], $names('żabka'));
        $this->assertSame(['Łódź Sp. z o.o.'], $names('pl1234'));
        $this->assertSame(['Müller GmbH'], $names('MUELLER.DE'));
        $this->assertSame([], $names('nothing'));
        $this->assertSame(1, $this->repo->count('acme'));
        $this->assertSame(4, $this->repo->count());
    }

    public function test_search_treats_wildcards_literally(): void
    {
        $this->repo->save(['name' => 'Acme']);
        $this->repo->save(['name' => 'Other']);
        $this->assertSame([], $this->repo->list('a%e'), 'a % in the term is not a wildcard');
        $this->assertSame([], $this->repo->list('A_me'), 'neither is _');
        $this->assertSame(2, count($this->repo->list('%')), 'a lone % is stripped, so it lists everything');
        $this->assertSame([], $this->repo->list("x' OR '1'='1"));
        $this->assertSame([], $this->repo->list('a\\b;--'));
    }

    public function test_list_limit_and_offset(): void
    {
        foreach (['A1', 'A2', 'A3'] as $n) {
            $this->repo->save(['name' => $n]);
        }
        $this->assertSame(['A2', 'A3'], array_column($this->repo->list('', 2, 1), 'name'));
    }

    public function test_vat_or_country_change_resets_vies(): void
    {
        $id = $this->repo->save(['name' => 'Acme', 'country' => 'PL', 'vat_id' => 'PL1234567890']);
        $at = new \DateTimeImmutable('2026-11-19 10:00:00', new \DateTimeZone('UTC'));
        $this->repo->recordVies($id, ViesStatus::Valid, $at, 'PL', 'PL1234567890');
        $row = $this->repo->find($id);
        $this->assertSame('valid', $row['vies_status']);
        $this->assertSame('2026-11-19 10:00:00', $row['vies_checked_at']);

        // same VAT id, written differently: not a change
        $this->repo->save(['name' => 'Acme 2', 'country' => 'PL', 'vat_id' => 'pl 1234567890'], $id);
        $this->assertSame('valid', $this->repo->find($id)['vies_status']);

        $this->repo->save(['name' => 'Acme 2', 'country' => 'PL', 'vat_id' => 'PL1234567891'], $id);
        $row = $this->repo->find($id);
        $this->assertNull($row['vies_status']);
        $this->assertNull($row['vies_checked_at']);

        $this->repo->recordVies($id, ViesStatus::Invalid, $at, 'PL', 'PL1234567891');
        $this->repo->save(['name' => 'Acme 2', 'country' => 'DE', 'vat_id' => 'DE1234567891'], $id);
        $this->assertNull($this->repo->find($id)['vies_status']);
    }

    public function test_record_vies_only_applies_to_the_vat_id_and_country_that_were_checked(): void
    {
        $id = $this->repo->save(['name' => 'Acme', 'country' => 'PL', 'vat_id' => 'PL1234567890']);
        $at = new \DateTimeImmutable('2026-11-19 10:00:00', new \DateTimeZone('UTC'));
        // The VAT id was edited while the VIES call was in flight: the old answer must not be attached to the new number.
        $this->repo->save(['name' => 'Acme', 'country' => 'PL', 'vat_id' => 'PL1234567891'], $id);
        $this->assertFalse($this->repo->recordVies($id, ViesStatus::Valid, $at, 'PL', 'PL1234567890'));
        $this->assertNull($this->repo->find($id)['vies_status']);
        $this->assertFalse($this->repo->recordVies($id, ViesStatus::Valid, $at, 'DE', 'PL1234567891'), 'country must match too');
        $this->assertNull($this->repo->find($id)['vies_status']);
        $this->assertTrue($this->repo->recordVies($id, ViesStatus::Valid, $at, 'PL', 'PL1234567891'));
        $this->assertSame('valid', $this->repo->find($id)['vies_status']);
        $this->assertFalse($this->repoB->recordVies($id, ViesStatus::Invalid, $at, 'PL', 'PL1234567891'), 'another company');
        $this->assertSame('valid', $this->repo->find($id)['vies_status']);
    }

    public function test_record_vies_converts_to_utc(): void
    {
        $id = $this->repo->save(['name' => 'Acme', 'country' => 'PL', 'vat_id' => 'PL1234567890']);
        $this->repo->recordVies($id, ViesStatus::Unavailable, new \DateTimeImmutable('2026-11-19 12:00:00', new \DateTimeZone('Europe/Berlin')), 'PL', 'PL1234567890');
        $row = $this->repo->find($id);
        $this->assertSame('unavailable', $row['vies_status']);
        $this->assertSame('2026-11-19 11:00:00', $row['vies_checked_at']);
    }

    public function test_other_company_customers_are_invisible_and_immovable(): void
    {
        $a = $this->repo->save(['name' => 'Alpha']);
        $b = $this->repoB->save(['name' => 'Beta', 'country' => 'PL', 'vat_id' => 'PL1234567890']);
        $this->assertNull($this->repo->find($b));
        $this->assertSame(['Alpha'], array_column($this->repo->list(), 'name'));
        $this->assertSame([], $this->repo->list('beta'));
        $this->assertSame(1, $this->repo->count());

        try {
            $this->repo->save(['name' => 'Hijacked'], $b);
            $this->fail('Expected not found');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame('Beta', $this->repoB->find($b)['name']);

        $this->assertFalse($this->repo->recordVies($b, ViesStatus::Valid, new \DateTimeImmutable(), 'PL', 'PL1234567890'));
        $this->assertNull($this->repoB->find($b)['vies_status']);

        // company_id in the payload is ignored
        $c = $this->repo->save(['name' => 'Gamma', 'company_id' => $this->companyB]);
        $this->assertSame($this->companyA, (int) $this->repo->find($c)['company_id']);
        $this->repo->save(['name' => 'Gamma2', 'company_id' => $this->companyB], $c);
        $this->assertSame($this->companyA, (int) $this->repo->find($c)['company_id']);
        $this->assertSame('Alpha', $this->repo->find($a)['name']);
    }
}
