<?php
declare(strict_types=1);

namespace Tests\Repo;

use App\Auth\UserService;
use App\Core\Crypto;
use App\Core\Db;
use App\Domain\ValidationException;
use App\Repo\CompanySettingsRepo;
use App\Tenant\CompanyContext;
use PHPUnit\Framework\TestCase;

final class CompanySettingsRepoTest extends TestCase
{
    private Db $db;
    private Crypto $crypto;
    private int $userId;

    protected function setUp(): void
    {
        $this->db = \TestDb::fresh();
        $this->crypto = new Crypto(base64_encode(str_repeat('k', 32)));
        $this->userId = (new UserService($this->db))->create('o@example.com', 'correct horse battery', false);
    }

    private function company(string $name = 'Muster GmbH'): int
    {
        $this->db->exec('INSERT INTO companies (name) VALUES (?)', [$name]);
        $id = $this->db->lastId();
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$id, $this->userId, 'owner']);
        return $id;
    }

    private function repo(int $companyId): CompanySettingsRepo
    {
        return new CompanySettingsRepo($this->db, CompanyContext::forUser($this->db, $this->userId, $companyId), $this->crypto, fn (): int => 1_700_000_000);
    }

    /** @return array<string, string> */
    private function companyInput(array $over = []): array
    {
        return $over + [
            'name' => 'Muster Service GmbH', 'address' => "Musterweg 1\n10115 Berlin", 'tax_number' => '12/345/67890',
            'vat_id' => 'de 987 654 321', 'iban' => 'de89 3704 0044 0532 0130 00', 'bic' => 'cobadeff xxx', 'bank' => 'Commerzbank',
            'default_lang' => 'de', 'default_currency' => 'EUR', 'default_payment_days' => '14',
            'intro_text' => 'Intro', 'outro_text' => 'Outro', 'footer_text' => 'Footer',
        ];
    }

    /** @return array<string, string> */
    private function dunningInput(array $over = []): array
    {
        return $over + [
            'dunning_d0' => '7', 'dunning_d1' => '21', 'dunning_d2' => '35', 'dunning_d3' => '49',
            'dunning_base_rate' => '', 'dunning_b2b' => '9', 'dunning_b2c' => '5', 'dunning_flat' => '40', 'dunning_fee' => '',
        ];
    }

    /** @return array<string, string> */
    private function mailInput(array $over = []): array
    {
        return $over + [
            'smtp_host' => 'smtp.example.com', 'smtp_port' => '465', 'smtp_user' => 'rechnung@example.com',
            'smtp_pass' => 'S3cret-Passw0rd!', 'smtp_from_name' => 'Muster', 'smtp_from_addr' => 'rechnung@example.com',
        ];
    }

    private function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors;
        }
        $this->fail('Expected ValidationException');
    }

    public function test_dunning_defaults_are_7_21_35_49_and_base_rate_null(): void
    {
        $s = $this->repo($this->company())->get();
        $this->assertSame([7, 21, 35, 49], [(int) $s['dunning_d0'], (int) $s['dunning_d1'], (int) $s['dunning_d2'], (int) $s['dunning_d3']]);
        $this->assertNull($s['dunning_base_rate_bp']);
        $this->assertSame(900, (int) $s['dunning_b2b_surcharge_bp']);
        $this->assertSame(500, (int) $s['dunning_b2c_surcharge_bp']);
        $this->assertSame(4000, (int) $s['dunning_flat_minor']);
        $this->assertSame(0, (int) $s['dunning_fee_minor']);
    }

    public function test_save_company_normalises_and_stores(): void
    {
        $id = $this->company();
        $repo = $this->repo($id);
        $repo->saveCompany($this->companyInput());
        $s = $repo->get();
        $this->assertSame('Muster Service GmbH', $s['name']);
        $this->assertSame('DE987654321', $s['vat_id']);
        $this->assertSame('DE89370400440532013000', $s['iban']);
        $this->assertSame('COBADEFFXXX', $s['bic']);
        $this->assertSame(14, (int) $s['default_payment_days']);
        $this->assertSame('Footer', $s['footer_text']);
    }

    public function test_vat_id_keeps_other_eu_prefix(): void
    {
        $repo = $this->repo($this->company());
        $repo->saveCompany($this->companyInput(['vat_id' => 'PL 123-456-32-18']));
        $this->assertSame('PL1234563218', $repo->get()['vat_id']);
        $repo->saveCompany($this->companyInput(['vat_id' => 'EL123456789']));
        $this->assertSame('EL123456789', $repo->get()['vat_id']);
    }

    public function test_empty_optionals_become_null(): void
    {
        $repo = $this->repo($this->company());
        $repo->saveCompany($this->companyInput());
        $repo->saveCompany($this->companyInput(['address' => '', 'tax_number' => '', 'vat_id' => '', 'iban' => '', 'bic' => '', 'bank' => '', 'intro_text' => '']));
        $s = $repo->get();
        foreach (['address', 'tax_number', 'vat_id', 'iban', 'bic', 'bank', 'intro_text'] as $f) {
            $this->assertNull($s[$f], $f);
        }
    }

    public function test_company_validation_rules(): void
    {
        $repo = $this->repo($this->company());
        $cases = [
            'name' => [['name' => ''], ['name' => str_repeat('ä', 191)]],
            'address' => [['address' => str_repeat('a', 501)]],
            'tax_number' => [['tax_number' => str_repeat('1', 41)]],
            'vat_id' => [['vat_id' => '!!??']],
            'iban' => [['iban' => 'DE88370400440532013000'], ['iban' => 'XX']],
            'bic' => [['bic' => 'ABC'], ['bic' => 'ABCDEFGHI'], ['bic' => 'ABCD-EFG']],
            'bank' => [['bank' => str_repeat('b', 191)]],
            'default_lang' => [['default_lang' => 'fr']],
            'default_currency' => [['default_currency' => 'GBP']],
            'default_payment_days' => [['default_payment_days' => '-1'], ['default_payment_days' => '366'], ['default_payment_days' => 'abc'], ['default_payment_days' => '']],
            'intro_text' => [['intro_text' => str_repeat('x', 2001)]],
            'outro_text' => [['outro_text' => str_repeat('x', 2001)]],
            'footer_text' => [['footer_text' => str_repeat('x', 2001)]],
        ];
        foreach ($cases as $field => $variants) {
            foreach ($variants as $v) {
                $errors = $this->errors(fn () => $repo->saveCompany($this->companyInput($v)));
                $this->assertArrayHasKey($field, $errors, $field . ' ' . json_encode($v));
                $this->assertStringStartsWith('settings.err_', $errors[$field]);
            }
        }
    }

    public function test_company_limits_are_inclusive(): void
    {
        $repo = $this->repo($this->company());
        $repo->saveCompany($this->companyInput([
            'name' => str_repeat('ä', 190), 'address' => str_repeat('a', 500), 'tax_number' => str_repeat('1', 40),
            'bank' => str_repeat('b', 190), 'intro_text' => str_repeat('x', 2000), 'default_payment_days' => '365',
            'default_lang' => 'pl', 'default_currency' => 'PLN', 'iban' => 'PL61 1090 1014 0000 0712 1981 2874', 'bic' => 'BREXPLPWMBK',
        ]));
        $repo->saveCompany($this->companyInput(['default_payment_days' => '0', 'default_lang' => 'en', 'default_currency' => 'USD']));
        $this->assertSame(0, (int) $repo->get()['default_payment_days']);
    }

    public function test_invalid_input_writes_nothing_and_no_audit(): void
    {
        $id = $this->company();
        $repo = $this->repo($id);
        $this->errors(fn () => $repo->saveCompany($this->companyInput(['iban' => 'DE00'])));
        $this->assertSame('Muster GmbH', $repo->get()['name']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS c FROM audit_log')['c']);
    }

    public function test_dunning_validation_and_ordering(): void
    {
        $repo = $this->repo($this->company());
        $e = $this->errors(fn () => $repo->saveDunning($this->dunningInput(['dunning_d0' => '21'])));
        $this->assertArrayHasKey('dunning_d1', $e); // 21 is not > 21
        $e = $this->errors(fn () => $repo->saveDunning($this->dunningInput(['dunning_d2' => '10'])));
        $this->assertArrayHasKey('dunning_d2', $e);
        $e = $this->errors(fn () => $repo->saveDunning($this->dunningInput(['dunning_d3' => '35'])));
        $this->assertArrayHasKey('dunning_d3', $e);
        foreach (['0', '366', 'x', '', '1.5'] as $bad) {
            $e = $this->errors(fn () => $repo->saveDunning($this->dunningInput(['dunning_d0' => $bad])));
            $this->assertArrayHasKey('dunning_d0', $e, $bad);
        }
        $repo->saveDunning($this->dunningInput(['dunning_d0' => '1', 'dunning_d1' => '2', 'dunning_d2' => '3', 'dunning_d3' => '365']));
        $this->assertSame(365, (int) $repo->get()['dunning_d3']);
    }

    public function test_base_rate_parsing_per_language(): void
    {
        $repo = $this->repo($this->company());
        $repo->saveDunning($this->dunningInput(['dunning_base_rate' => '1,27']), 'de');
        $this->assertSame(127, (int) $repo->get()['dunning_base_rate_bp']);
        $repo->saveDunning($this->dunningInput(['dunning_base_rate' => '1,27']), 'pl');
        $this->assertSame(127, (int) $repo->get()['dunning_base_rate_bp']);
        $repo->saveDunning($this->dunningInput(['dunning_base_rate' => '2.5']), 'en');
        $this->assertSame(250, (int) $repo->get()['dunning_base_rate_bp']);
        $repo->saveDunning($this->dunningInput(['dunning_base_rate' => '1.27']), 'en');
        $this->assertSame(127, (int) $repo->get()['dunning_base_rate_bp']);
        $repo->saveDunning($this->dunningInput(['dunning_base_rate' => '30']), 'de');
        $this->assertSame(3000, (int) $repo->get()['dunning_base_rate_bp']);
        $repo->saveDunning($this->dunningInput(['dunning_base_rate' => '0']), 'de');
        $this->assertSame(0, (int) $repo->get()['dunning_base_rate_bp']);
        $repo->saveDunning($this->dunningInput(['dunning_base_rate' => '']), 'de');
        $this->assertNull($repo->get()['dunning_base_rate_bp']);
        foreach (['31', '30,01', '1,234', 'abc', '-1'] as $bad) {
            $e = $this->errors(fn () => $repo->saveDunning($this->dunningInput(['dunning_base_rate' => $bad]), 'de'));
            $this->assertArrayHasKey('dunning_base_rate', $e, $bad);
        }
    }

    public function test_surcharges_flat_fee_and_fee(): void
    {
        $repo = $this->repo($this->company());
        $repo->saveDunning($this->dunningInput(['dunning_b2b' => '8,5', 'dunning_b2c' => '20', 'dunning_flat' => '1.000,00', 'dunning_fee' => '2,50']), 'de');
        $s = $repo->get();
        $this->assertSame(850, (int) $s['dunning_b2b_surcharge_bp']);
        $this->assertSame(2000, (int) $s['dunning_b2c_surcharge_bp']);
        $this->assertSame(100000, (int) $s['dunning_flat_minor']);
        $this->assertSame(250, (int) $s['dunning_fee_minor']);
        $repo->saveDunning($this->dunningInput(['dunning_flat' => '0', 'dunning_fee' => '']), 'de');
        $this->assertSame(0, (int) $repo->get()['dunning_flat_minor']);
        $this->assertSame(0, (int) $repo->get()['dunning_fee_minor']);

        foreach ([
            'dunning_b2b' => ['20,01', '21', '', 'x', '-1'],
            'dunning_b2c' => ['20,01', ''],
            'dunning_flat' => ['1.000,01', '1001', '', '-5', 'abc'],
            'dunning_fee' => ['1000,01', '-1', 'abc'],
        ] as $field => $bads) {
            foreach ($bads as $bad) {
                $e = $this->errors(fn () => $repo->saveDunning($this->dunningInput([$field => $bad]), 'de'));
                $this->assertArrayHasKey($field, $e, $field . ' ' . $bad);
            }
        }
    }

    public function test_smtp_password_never_stored_plain(): void
    {
        $id = $this->company();
        $repo = $this->repo($id);
        $repo->saveMail($this->mailInput());
        $stored = (string) $this->db->one('SELECT smtp_pass_enc FROM companies WHERE id = ?', [$id])['smtp_pass_enc'];
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('S3cret', $stored);
        $this->assertNotSame('S3cret-Passw0rd!', $stored);
        $this->assertSame('S3cret-Passw0rd!', $this->crypto->decrypt($stored));
        $s = $repo->get();
        $this->assertArrayNotHasKey('smtp_pass_enc', $s);
        $this->assertArrayNotHasKey('smtp_pass', $s);
        $this->assertTrue($s['smtp_pass_set']);
        $this->assertStringNotContainsString('S3cret', json_encode($s));
        $this->assertStringNotContainsString($stored, json_encode($s));
        // Not in the audit log either.
        foreach ($this->db->all('SELECT * FROM audit_log') as $row) {
            $this->assertStringNotContainsString('S3cret', json_encode($row));
            $this->assertStringNotContainsString($stored, json_encode($row));
        }
    }

    public function test_blank_password_keeps_the_old_one(): void
    {
        $id = $this->company();
        $repo = $this->repo($id);
        $repo->saveMail($this->mailInput());
        $before = $this->db->one('SELECT smtp_pass_enc FROM companies WHERE id = ?', [$id])['smtp_pass_enc'];
        $repo->saveMail($this->mailInput(['smtp_pass' => '', 'smtp_host' => 'mail.example.org']));
        $this->assertSame($before, $this->db->one('SELECT smtp_pass_enc FROM companies WHERE id = ?', [$id])['smtp_pass_enc']);
        $this->assertSame('mail.example.org', $repo->get()['smtp_host']);
        $repo->saveMail($this->mailInput(['smtp_pass' => 'another-Secret-1']));
        $this->assertNotSame($before, $this->db->one('SELECT smtp_pass_enc FROM companies WHERE id = ?', [$id])['smtp_pass_enc']);
    }

    public function test_the_clear_checkbox_removes_the_stored_password_and_is_audited_by_field_name(): void
    {
        $id = $this->company();
        $repo = $this->repo($id);
        $repo->saveMail($this->mailInput());
        $this->assertTrue($repo->get()['smtp_pass_set']);
        $repo->saveMail($this->mailInput(['smtp_pass' => '', 'smtp_pass_clear' => '1']));
        $this->assertNull($this->db->one('SELECT smtp_pass_enc FROM companies WHERE id = ?', [$id])['smtp_pass_enc']);
        $this->assertFalse($repo->get()['smtp_pass_set']);
        $last = $this->db->one("SELECT detail FROM audit_log WHERE action = 'settings.mail_save' ORDER BY id DESC LIMIT 1");
        $this->assertContains('smtp_pass', json_decode((string) $last['detail'], true)['fields']);
        // Without the checkbox a blank field still keeps whatever is stored; a typed password wins over the checkbox.
        $repo->saveMail($this->mailInput(['smtp_pass' => 'New-Secret-12']));
        $repo->saveMail($this->mailInput(['smtp_pass' => '']));
        $this->assertTrue($repo->get()['smtp_pass_set']);
        $repo->saveMail($this->mailInput(['smtp_pass' => 'Other-Secret-34', 'smtp_pass_clear' => '1']));
        $this->assertTrue($repo->get()['smtp_pass_set'], 'a typed password is stored even when the box is ticked');
    }

    public function test_mail_validation_and_partial_save(): void
    {
        $repo = $this->repo($this->company());
        foreach ([
            'smtp_host' => ['bad host!', str_repeat('a', 191), '-x.example.com', 'a..b'],
            'smtp_port' => ['0', '65536', 'abc', '-1', '25.5'],
            'smtp_user' => [str_repeat('u', 191)],
            'smtp_from_name' => [str_repeat('n', 191)],
            'smtp_from_addr' => ['not-an-email', 'a@b', str_repeat('a', 185) . '@example.com'],
        ] as $field => $bads) {
            foreach ($bads as $bad) {
                $e = $this->errors(fn () => $repo->saveMail($this->mailInput([$field => $bad])));
                $this->assertArrayHasKey($field, $e, $field . ' ' . $bad);
            }
        }
        // IP literal and a plain hostname are fine; everything may be empty.
        $repo->saveMail($this->mailInput(['smtp_host' => '192.0.2.10', 'smtp_port' => '587']));
        $this->assertSame('192.0.2.10', $repo->get()['smtp_host']);
        $repo->saveMail(['smtp_host' => '', 'smtp_port' => '', 'smtp_user' => '', 'smtp_pass' => '', 'smtp_from_name' => '', 'smtp_from_addr' => '']);
        $s = $repo->get();
        $this->assertNull($s['smtp_host']);
        $this->assertNull($s['smtp_port']);
        $this->assertTrue($s['smtp_pass_set'], 'blank password keeps the stored one');
        $this->assertFalse($repo->mailConfigured());
    }

    public function test_mail_configured_needs_all_five(): void
    {
        $repo = $this->repo($this->company());
        $this->assertFalse($repo->mailConfigured());
        $repo->saveMail($this->mailInput());
        $this->assertTrue($repo->mailConfigured());
        foreach (['smtp_host', 'smtp_port', 'smtp_user', 'smtp_from_addr'] as $f) {
            $repo->saveMail($this->mailInput([$f => '']));
            $this->assertFalse($repo->mailConfigured(), $f);
            $repo->saveMail($this->mailInput());
        }
    }

    public function test_settings_scoped_to_company(): void
    {
        $a = $this->company('A GmbH');
        $b = $this->company('B GmbH');
        $this->repo($a)->saveCompany($this->companyInput(['name' => 'A neu', 'id' => (string) $b, 'company_id' => (string) $b, 'plan' => 'hack', 'access_status' => 'active']));
        $this->repo($a)->saveDunning($this->dunningInput(['id' => (string) $b, 'dunning_d0' => '3']));
        $this->repo($a)->saveMail($this->mailInput(['id' => (string) $b]));
        $this->repo($a)->setLogo('logos/company-' . $a . '.png');
        $rowB = $this->db->one('SELECT * FROM companies WHERE id = ?', [$b]);
        $this->assertSame('B GmbH', $rowB['name']);
        $this->assertSame(7, (int) $rowB['dunning_d0']);
        $this->assertNull($rowB['smtp_host']);
        $this->assertNull($rowB['logo_path']);
        $this->assertSame('free', $rowB['plan']);
        $rowA = $this->db->one('SELECT * FROM companies WHERE id = ?', [$a]);
        $this->assertSame('A neu', $rowA['name']);
        $this->assertSame('free', $rowA['plan']);
        $this->assertSame('logos/company-' . $a . '.png', $rowA['logo_path']);
        $this->assertSame($a, (int) $this->repo($a)->get()['id']);
    }

    public function test_audit_rows_list_field_names_only(): void
    {
        $id = $this->company();
        $repo = $this->repo($id);
        $repo->saveCompany($this->companyInput(['name' => 'Geheim GmbH Neu']));
        $repo->saveDunning($this->dunningInput(['dunning_d0' => '5']));
        $repo->saveMail($this->mailInput());
        $repo->setLogo("logos/company-{$id}.png");
        $rows = $this->db->all('SELECT * FROM audit_log ORDER BY id');
        $this->assertSame(['settings.company_save', 'settings.dunning_save', 'settings.mail_save', 'settings.logo_save'], array_column($rows, 'action'));
        foreach ($rows as $r) {
            $this->assertSame($id, (int) $r['company_id']);
            $this->assertSame($this->userId, (int) $r['user_id']);
        }
        $company = json_decode((string) $rows[0]['detail'], true);
        $this->assertContains('name', $company['fields']);
        $this->assertContains('iban', $company['fields']);
        $this->assertStringNotContainsString('Geheim', (string) $rows[0]['detail']);
        $this->assertStringNotContainsString('DE89', (string) $rows[0]['detail']);
        $this->assertSame(['dunning_d0'], json_decode((string) $rows[1]['detail'], true)['fields']);
        $mail = json_decode((string) $rows[2]['detail'], true)['fields'];
        $this->assertContains('smtp_host', $mail);
        $this->assertStringNotContainsString('example.com', (string) $rows[2]['detail']);
        $this->assertStringNotContainsString('S3cret', (string) $rows[2]['detail']);
    }

    public function test_save_mail_without_crypto_refuses_a_password(): void
    {
        $id = $this->company();
        $repo = new CompanySettingsRepo($this->db, CompanyContext::forUser($this->db, $this->userId, $id));
        $this->expectException(\LogicException::class);
        $repo->saveMail($this->mailInput());
    }

    public function test_mail_target_guard_with_policy(): void
    {
        $id = $this->company();
        $policy = new \App\Mail\SmtpTargetPolicy(new \Tests\Support\FakeResolver(['smtp.example.com' => ['93.184.216.34'], 'lan.example' => ['10.1.1.1']]));
        $repo = new CompanySettingsRepo($this->db, CompanyContext::forUser($this->db, $this->userId, $id), $this->crypto, fn (): int => 1_700_000_000, $policy);
        foreach ([['smtp_host', 'lan.example'], ['smtp_host', '127.0.0.1'], ['smtp_host', 'weg.example'], ['smtp_port', '6379']] as [$field, $value]) {
            try {
                $repo->saveMail($this->mailInput([$field => $value]));
                $this->fail("$field=$value must be refused");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors);
            }
        }
        $repo->saveMail($this->mailInput(['smtp_host' => 'smtp.example.com', 'smtp_port' => '587']));
        $this->assertSame('smtp.example.com', $repo->get()['smtp_host']);
    }
}
