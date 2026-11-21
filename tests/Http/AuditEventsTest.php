<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Domain\DocumentService;
use App\Domain\NumberSequences;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Tenant\CompanyContext;
use Tests\Pdf\PdfFixture;
use Tests\Support\FakeResolver;
use Tests\Support\FakeTransport;

/** Audit events of the auth flows and of the main business actions (Task 23). */
final class AuditEventsTest extends HttpTestCase
{
    private const EMAIL = 'jan@example.pl';

    private int $companyId;
    private int $userId;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $u = $this->createUser(self::EMAIL);
        $this->userId = $u['id'];
        $this->secret = $u['secret'];
        $this->companyId = $this->createCompany('Muster GmbH');
        $this->addMember($this->userId, $this->companyId);
    }

    /** @return list<array<string, mixed>> audit rows of one action */
    private function rows(string $action): array
    {
        return $this->db->all('SELECT * FROM audit_log WHERE action = ? ORDER BY id', [$action]);
    }

    private function key(string $email, ?string $secret = null): string
    {
        $secret ??= $this->app->crypto()->deriveKey('faktura/audit/login-key');
        return substr(hash_hmac('sha256', strtolower($email), $secret), 0, 12);
    }

    public function test_successful_login_is_recorded_once_per_step_with_the_user(): void
    {
        $this->loginFully($this->secret);
        $login = $this->rows('auth.password_ok');
        $this->assertCount(1, $login);
        $this->assertSame($this->userId, (int) $login[0]['user_id']);
        $this->assertNull($login[0]['company_id']);
        $this->assertCount(1, $this->rows('auth.2fa_ok'));
        $this->assertSame($this->userId, (int) $this->rows('auth.2fa_ok')[0]['user_id']);
        $this->assertSame([], $this->rows('auth.backup_code_used'));
        $this->assertSame([], $this->rows('auth.login_failed'));
        $this->assertSame([], $this->rows('auth.2fa_failed'));
    }

    public function test_failed_logins_record_reason_and_key_hash_but_never_the_email(): void
    {
        $this->loginPassword(self::EMAIL, 'wrong password!!');
        $this->loginPassword('Nobody@Example.com', 'whatever whatever');
        $rows = $this->rows('auth.login_failed');
        $this->assertCount(2, $rows);
        $this->assertSame(['reason' => 'bad_password', 'key' => $this->key(self::EMAIL)], json_decode($rows[0]['detail'], true));
        $this->assertSame($this->key('nobody@example.com'), json_decode($rows[1]['detail'], true)['key'], 'the key is case-insensitive');
        $this->assertSame('unknown_user', json_decode($rows[1]['detail'], true)['reason']);
        $this->assertNull($rows[0]['user_id']);
        $this->assertSame(12, strlen(json_decode($rows[0]['detail'], true)['key']));
        $this->assertSame([], $this->rows('auth.password_ok'));
    }

    public function test_login_failed_key_is_an_hmac_not_a_plain_hash_of_the_email(): void
    {
        $this->loginPassword(self::EMAIL, 'wrong password!!');
        $key = json_decode($this->rows('auth.login_failed')[0]['detail'], true)['key'];
        $this->assertSame($this->key(self::EMAIL), $key);
        $this->assertNotSame(substr(hash('sha256', self::EMAIL), 0, 12), $key, 'a dictionary of known addresses cannot be hashed without the secret');
    }

    public function test_login_failed_key_uses_the_configured_audit_secret_when_there_is_one(): void
    {
        $this->app = $this->makeApp(['audit_key' => 'a separate secret']);
        $this->loginPassword(self::EMAIL, 'wrong password!!');
        $this->assertSame($this->key(self::EMAIL, 'a separate secret'), json_decode($this->rows('auth.login_failed')[0]['detail'], true)['key']);
        $this->assertNotSame($this->key(self::EMAIL), $this->key(self::EMAIL, 'a separate secret'));
    }

    public function test_locked_login_is_recorded_once_per_key_and_window(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->loginPassword(self::EMAIL, 'wrong password!!');
        }
        $this->assertSame(429, $this->loginPassword(self::EMAIL, self::PASSWORD)->status);
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(429, $this->loginPassword(self::EMAIL, self::PASSWORD)->status);
        }
        $rows = $this->rows('auth.login_failed');
        $this->assertCount(6, $rows, 'five failures and ONE locked row, however often the locked account is tried');
        $this->assertSame('locked', json_decode($rows[5]['detail'], true)['reason']);
        $this->assertSame([], $this->rows('auth.password_ok'), 'the locked attempt never logs in');
        // another address from the same locked IP has its own key and gets its own single row
        $this->assertSame(429, $this->loginPassword('other@example.pl', self::PASSWORD)->status);
        $this->assertSame(429, $this->loginPassword('other@example.pl', self::PASSWORD)->status);
        $this->assertCount(7, $this->rows('auth.login_failed'));
    }

    public function test_a_locked_row_is_written_again_after_the_window(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->loginPassword(self::EMAIL, 'wrong password!!');
        }
        $this->loginPassword(self::EMAIL, self::PASSWORD);
        $this->assertCount(6, $this->rows('auth.login_failed'));
        $this->now += 901;
        for ($i = 0; $i < 5; $i++) {
            $this->loginPassword(self::EMAIL, 'wrong password!!');
        }
        $this->loginPassword(self::EMAIL, self::PASSWORD);
        $this->loginPassword(self::EMAIL, self::PASSWORD);
        $this->assertCount(12, $this->rows('auth.login_failed'));
    }

    public function test_a_failing_audit_write_cannot_break_a_backup_code_login_or_a_company_switch(): void
    {
        $second = $this->createCompany('Zweite AG');
        $this->addMember($this->userId, $second);
        $codes = $this->app->backupCodes()->generate($this->userId);
        $this->assertRedirect('/login/2fa', $this->loginPassword());
        $this->db->exec('DROP TABLE audit_log');
        $log = tempnam(sys_get_temp_dir(), 'auditlog');
        $old = ini_set('error_log', $log);
        try {
            $this->assertRedirect('/', $this->post('/login/2fa', ['code' => $codes[0]]));
            $this->assertRedirect('/', $this->post('/company/switch', ['company_id' => (string) $second]));
        } finally {
            ini_set('error_log', (string) $old);
            unlink($log);
        }
        $this->assertSame($second, $this->app->session()->companyId(), 'the switch happened');
        $this->assertFalse($this->app->backupCodes()->consume($this->userId, $codes[0]), 'the code was consumed');
    }

    public function test_wrong_second_factor_is_recorded_for_the_user(): void
    {
        $this->assertRedirect('/login/2fa', $this->loginPassword());
        $this->assertSame(422, $this->post('/login/2fa', ['code' => '000000'])->status);
        $rows = $this->rows('auth.2fa_failed');
        $this->assertCount(1, $rows);
        $this->assertSame($this->userId, (int) $rows[0]['user_id']);
        $this->assertSame(['reason' => 'invalid'], json_decode($rows[0]['detail'], true));
        $this->assertSame([], $this->rows('auth.2fa_ok'));
        $this->assertStringNotContainsString('000000', (string) $rows[0]['detail']);
    }

    public function test_backup_code_login_records_2fa_ok_and_backup_code_used_once(): void
    {
        $codes = $this->app->backupCodes()->generate($this->userId);
        $this->assertRedirect('/login/2fa', $this->loginPassword());
        $this->assertRedirect('/', $this->post('/login/2fa', ['code' => $codes[0]]));
        $this->assertCount(1, $this->rows('auth.backup_code_used'));
        $this->assertSame($this->userId, (int) $this->rows('auth.backup_code_used')[0]['user_id']);
        $this->assertCount(1, $this->rows('auth.2fa_ok'));
        $this->assertStringNotContainsString($codes[0], json_encode($this->db->all('SELECT * FROM audit_log')));
    }

    public function test_logout_is_recorded_once_and_an_anonymous_logout_writes_nothing(): void
    {
        $this->post('/logout');
        $this->assertSame([], $this->rows('auth.logout'));
        $this->loginFully($this->secret);
        $this->assertRedirect('/login', $this->post('/logout'));
        $rows = $this->rows('auth.logout');
        $this->assertCount(1, $rows);
        $this->assertSame($this->userId, (int) $rows[0]['user_id']);
        $this->post('/logout');
        $this->assertCount(1, $this->rows('auth.logout'));
    }

    public function test_enabling_two_factor_is_recorded(): void
    {
        $new = $this->createUser('neu@example.pl', totp: false);
        $this->assertRedirect('/2fa/setup', $this->loginPassword('neu@example.pl'));
        $this->get('/2fa/setup');
        $secret = $this->app->auth()->pendingTotpSecret($new['id']);
        $this->assertSame(422, $this->post('/2fa/setup', ['code' => '111111'])->status);
        $this->assertSame([], $this->rows('auth.2fa_enabled'));
        $this->assertSame(200, $this->post('/2fa/setup', ['code' => $this->code($secret)])->status);
        $rows = $this->rows('auth.2fa_enabled');
        $this->assertCount(1, $rows);
        $this->assertSame($new['id'], (int) $rows[0]['user_id']);
    }

    public function test_company_switch_is_recorded_with_the_company_and_foreign_switch_is_not(): void
    {
        $second = $this->createCompany('Zweite AG');
        $foreign = $this->createCompany('Fremd AG');
        $this->addMember($this->userId, $second);
        $this->loginFully($this->secret);
        $this->assertSame(403, $this->post('/company/switch', ['company_id' => (string) $foreign])->status);
        $this->assertSame([], $this->rows('company.switch'));
        $this->assertRedirect('/', $this->post('/company/switch', ['company_id' => (string) $second]));
        $rows = $this->rows('company.switch');
        $this->assertCount(1, $rows);
        $this->assertSame($second, (int) $rows[0]['company_id']);
        $this->assertSame($this->userId, (int) $rows[0]['user_id']);
        $this->assertSame(['company_id' => $second], json_decode($rows[0]['detail'], true));
    }

    public function test_audit_records_login_issue_send_and_settings_change(): void
    {
        $transport = new FakeTransport();
        $storage = sys_get_temp_dir() . '/faktura-pdf-route-storage';
        if (!is_dir($storage . '/logos')) {
            mkdir($storage . '/logos', 0775, true);
        }
        $this->app = $this->makeApp(['storage_path' => $storage, 'mail_transport' => $transport, 'mail_host_resolver' => FakeResolver::publicHosts()]);
        $this->now = (new \DateTimeImmutable('2026-11-18 10:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp();
        $this->db->exec(
            'UPDATE companies SET name = ?, address = ?, vat_id = ?, iban = ?, smtp_host = ?, smtp_port = 587, smtp_user = ?, smtp_pass_enc = ?, smtp_from_name = ?, smtp_from_addr = ? WHERE id = ?',
            ['Muster Service GmbH', "Musterweg 1\n10115 Berlin", 'DE987654321', 'DE89370400440532013000', 'smtp.firma.example', 'u@firma.example',
                $this->app->crypto()->encrypt('S3cr3t-Passw0rd!'), 'Muster', 'rechnung@firma.example', $this->companyId]
        );
        $ctx = CompanyContext::forUser($this->db, $this->userId, $this->companyId);
        $clock = fn (): int => $this->now;
        $svc = new DocumentService($this->db, $ctx, new NumberSequences($this->db, $ctx, $clock), new DocumentRepo($this->db, $ctx), new CustomerRepo($this->db, $ctx), $clock);
        $customer = (new CustomerRepo($this->db, $ctx))->save(['name' => 'Bauhaus Logistik GmbH', 'address' => "Hauptstraße 5\n80331 München", 'country' => 'DE',
            'vat_id' => 'DE123456789', 'is_company' => '1', 'payment_days' => '14', 'default_lang' => 'de', 'default_currency' => 'EUR']);
        $doc = $svc->createDraft('invoice', ['customer_id' => $customer, 'issue_date' => '2026-11-18', 'lines' => PdfFixture::makietaLines()]);

        $this->loginFully($this->secret);
        $svc->issue($doc, new \DateTimeImmutable('2026-11-18 10:00', new \DateTimeZone('Europe/Berlin')), true);
        $send = $this->post("/documents/$doc/send", ['to' => 'kunde@example.de', 'subject' => 'Rechnung', 'body' => 'Hallo']);
        $this->assertContains($send->status, [302, 303], substr($send->body, 0, 300));
        $this->assertSame(302, $this->post('/settings/company', [
            'name' => 'Muster Service GmbH', 'address' => 'Neu 1', 'tax_number' => '12/345/67890', 'vat_id' => 'DE987654321', 'iban' => '',
            'bic' => '', 'bank' => '', 'default_lang' => 'de', 'default_currency' => 'EUR', 'default_payment_days' => '14',
            'intro_text' => '', 'outro_text' => '', 'footer_text' => '',
        ])->status);

        foreach (['auth.password_ok' => 1, 'auth.2fa_ok' => 1, 'document.issue' => 1, 'document.email_send' => 1, 'settings.company_save' => 1] as $action => $n) {
            $rows = $this->rows($action);
            $this->assertCount($n, $rows, $action);
            $this->assertSame($this->userId, (int) $rows[0]['user_id'], $action);
        }
        foreach (['document.issue', 'document.email_send', 'settings.company_save'] as $action) {
            $this->assertSame($this->companyId, (int) $this->rows($action)[0]['company_id'], $action);
        }
        $this->assertSame(1, count($transport->sent));
    }

    public function test_no_audit_detail_ever_holds_an_email_password_code_ip_or_token(): void
    {
        // A long scenario: failures, locks, 2FA, backup codes, switch, logout, password change.
        $codes = $this->app->backupCodes()->generate($this->userId);
        $second = $this->createCompany('Zweite AG');
        $this->addMember($this->userId, $second);
        $this->loginPassword('ghost@example.com', 'ghost password 1');
        $this->loginPassword(self::EMAIL, 'wrong password!!');
        $this->assertRedirect('/login/2fa', $this->loginPassword());
        $this->post('/login/2fa', ['code' => '123456']);
        $this->assertRedirect('/', $this->post('/login/2fa', ['code' => $codes[2]]));
        $this->post('/company/switch', ['company_id' => (string) $second]);
        $this->post('/settings/security/password', [
            'current_password' => self::PASSWORD, 'new_password' => 'brand new passphrase 99', 'new_password_confirm' => 'brand new passphrase 99',
        ]);
        $this->post('/settings/security/backup-codes', ['current_password' => 'brand new passphrase 99']);
        $this->post('/logout');
        for ($i = 0; $i < 6; $i++) {
            $this->loginPassword(self::EMAIL, 'wrong password!!');
        }

        $all = $this->db->all('SELECT * FROM audit_log');
        $this->assertGreaterThan(10, count($all));
        $blob = json_encode($all, JSON_UNESCAPED_UNICODE);
        foreach ([self::EMAIL, 'ghost@', '@example', self::PASSWORD, 'brand new', 'wrong password', self::IP, $codes[2], $codes[0], '123456',
            $this->secret, 'argon2', 'csrf', $this->app->csrf()->token(), session_name(), 'faktura_sid'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $blob, "audit_log contains '$needle'");
        }
        foreach ($all as $row) {
            $this->assertMatchesRegularExpression('/\A[a-z0-9_.]{1,64}\z/', (string) $row['action']);
            $this->assertLessThanOrEqual(2000, strlen((string) $row['detail']));
        }
    }
}
