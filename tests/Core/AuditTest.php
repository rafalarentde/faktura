<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Audit;
use App\Core\Db;
use PHPUnit\Framework\TestCase;

/** App\Core\Audit: the single writer of audit_log, with a guard against PII and secrets in the detail. */
final class AuditTest extends TestCase
{
    private Db $db;
    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        $this->db = \TestDb::fresh();
    }

    private function audit(?int $user = null, ?int $company = null): Audit
    {
        return new Audit($this->db, fn (): int => $this->now, fn (): ?int => $user, fn (): ?int => $company);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->db->all('SELECT * FROM audit_log ORDER BY id');
    }

    public function test_log_writes_one_row_with_explicit_ids_and_utc_time(): void
    {
        $this->audit()->log('document.issue', ['document_id' => 5, 'type' => 'invoice', 'vat_confirmed' => true], 3, 7);
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('document.issue', $rows[0]['action']);
        $this->assertSame(3, (int) $rows[0]['company_id']);
        $this->assertSame(7, (int) $rows[0]['user_id']);
        $this->assertSame('2023-11-14 22:13:20', $rows[0]['at']);
        $this->assertSame('{"document_id":5,"type":"invoice","vat_confirmed":true}', $rows[0]['detail']);
    }

    public function test_ids_are_resolved_from_the_session_and_context_when_not_given(): void
    {
        $this->audit(11, 22)->log('x.y');
        $this->audit(11, 22)->log('x.z', [], 5, 6);
        $this->audit(11, 22)->log('x.none', [], Audit::NO_COMPANY);
        $rows = $this->rows();
        $this->assertSame([22, 11], [(int) $rows[0]['company_id'], (int) $rows[0]['user_id']]);
        $this->assertSame([5, 6], [(int) $rows[1]['company_id'], (int) $rows[1]['user_id']]);
        $this->assertNull($rows[2]['company_id']);
        $this->assertSame(11, (int) $rows[2]['user_id']);
    }

    public function test_without_resolvers_ids_are_null_and_empty_detail_is_an_object(): void
    {
        (new Audit($this->db, fn (): int => $this->now))->log('x.y');
        $row = $this->rows()[0];
        $this->assertNull($row['company_id']);
        $this->assertNull($row['user_id']);
        $this->assertSame('{}', $row['detail']);
    }

    public function test_explicit_time_is_used(): void
    {
        $this->audit()->log('x.y', [], null, null, 86_400);
        $this->assertSame('1970-01-02 00:00:00', $this->rows()[0]['at']);
    }

    public function test_action_names_are_normalised_or_rejected(): void
    {
        $a = $this->audit();
        $a->log('Auth.Login-Failed ÄÖ');
        $this->assertSame('auth.login_failed_', $this->rows()[0]['action']);
        $a->log(str_repeat('a', 100));
        $this->assertSame(64, strlen($this->rows()[1]['action']));
        foreach (['', '   ', '...'] as $bad) {
            try {
                $a->log($bad);
                $this->fail("accepted '$bad'");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertCount(2, $this->rows());
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function badDetails(): iterable
    {
        // Unknown keys are rejected whatever their name, and also under an allowlisted action.
        foreach (['password', 'new_password', 'pass', 'smtp_pass', 'secret', 'totp_secret', 'token', 'csrf_token', 'email', 'user_email', 'userEmail',
            'mail', 'recipient', 'remote_addr', 'iban', 'bic', 'body', 'subject', 'address', 'name', 'company_name', 'ip', 'Email', 'detail', 'x'] as $key) {
            yield "key $key" => ['auth.login_failed', [$key => 'x']];
            yield "key $key (export)" => ['export.create', [$key => 1]];
        }
        yield 'name outside company_create' => ['admin.user_add', ['name' => 'Muster GmbH']];
        yield 'email outside user_add' => ['admin.company_create', ['email' => 'a@b.example']];
        yield 'long string' => ['auth.login_failed', ['reason' => str_repeat('a', 81)]];
        yield 'nested array' => ['export.create', ['year' => ['id' => 1]]];
        yield 'object' => ['export.create', ['year' => new \stdClass()]];
        yield 'fields with free text' => ['settings.company_save', ['fields' => ['hello world, this is text']]];
        yield 'fields not a list of strings' => ['settings.company_save', ['fields' => [1, 2]]];
        yield 'fields under a key that takes none' => ['export.create', ['fields' => ['a']]];
        yield 'int key' => ['auth.login_failed', [0 => 'x']];
        yield 'unknown action with a key' => ['x.y', ['reason' => 'x']];
        yield 'new action with a key' => ['something.new', ['n' => 5]];
        // Value checks: even an allowlisted key must not carry PII-looking data.
        yield 'email in reason' => ['auth.login_failed', ['reason' => 'jan@example.pl']];
        yield 'email inside text' => ['auth.login_failed', ['reason' => 'failed for jan@example.pl today']];
        yield 'email in key' => ['auth.login_failed', ['key' => 'a@b.c']];
        yield 'email in mail reason' => ['document.email_send', ['document_id' => 1, 'status' => 'failed', 'reason' => 'x@y.pl']];
        yield 'ipv4' => ['auth.login_failed', ['reason' => '203.0.113.5']];
        yield 'ipv4 in text' => ['auth.login_failed', ['reason' => 'from 203.0.113.5!']];
        yield 'ipv6' => ['auth.login_failed', ['reason' => '2001:db8::1']];
        yield 'ipv6 full' => ['auth.login_failed', ['reason' => '2001:0db8:0000:0000:0000:ff00:0042:8329']];
        yield 'ipv6 loopback' => ['auth.login_failed', ['reason' => '::1']];
        yield 'iban' => ['auth.login_failed', ['reason' => 'PL61109010140000071219812874']];
        yield 'iban with spaces' => ['auth.login_failed', ['reason' => 'DE89 3704 0044 0532 0130 00']];
        yield 'iban lower case' => ['auth.login_failed', ['reason' => 'de89370400440532013000']];
        yield 'iban in mail test reason' => ['settings.mail_test', ['status' => 'failed', 'reason' => 'DE89370400440532013000']];
        yield 'email in status of other action' => ['admin.access_change', ['company_id' => 1, 'old_status' => 'a@b.example']];
        yield 'company name too long' => ['admin.company_create', ['name' => str_repeat('a', 191)]];
        yield 'email too long' => ['admin.user_add', ['email' => str_repeat('a', 191)]];
    }

    /** @param array<string, mixed> $detail */
    #[\PHPUnit\Framework\Attributes\DataProvider('badDetails')]
    public function test_pii_guard_rejects_log(string $action, array $detail): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->audit()->log($action, $detail);
        } finally {
            $this->assertSame([], $this->rows(), 'nothing is written');
        }
    }

    public function test_safe_shapes_are_accepted(): void
    {
        $a = $this->audit();
        $a->log('settings.company_save', ['fields' => ['name', 'smtp_pass', 'address']]);
        $a->log('auth.login_failed', ['reason' => str_repeat('a', 80), 'key' => 'ab12cd34ef56']);
        $a->log('document.issue', ['document_id' => 5, 'type' => 'invoice', 'number' => 'RE-2026/001', 'vat_confirmed' => false]);
        $a->log('export.create', ['include_pdfs' => true, 'year' => null, 'doc_count' => 5, 'pdf_count' => 2]);
        $a->log('x.y');
        $this->assertCount(5, $this->rows());
        $this->assertSame('{"fields":["name","smtp_pass","address"]}', $this->rows()[0]['detail']);
        $this->assertStringContainsString('RE-2026/001', $this->rows()[2]['detail'], 'slashes stay readable');
    }

    public function test_numbers_that_look_like_an_iban_are_fine_for_the_document_number(): void
    {
        $this->audit()->log('document.issue', ['document_id' => 1, 'type' => 'invoice', 'number' => 'RE20260000001234', 'vat_confirmed' => true]);
        $this->assertCount(1, $this->rows());
    }

    public function test_dates_and_codes_are_not_mistaken_for_pii(): void
    {
        $a = $this->audit();
        $a->log('admin.access_change', ['company_id' => 1, 'old_status' => 'active', 'new_status' => 'suspended', 'old_until' => '2026-11-18', 'new_until' => null]);
        $a->log('document.offer_status', ['document_id' => 1, 'from' => 'open', 'to' => 'sent']);
        $a->log('auth.login_failed', ['reason' => 'unknown_user', 'key' => '0123456789ab']);
        $this->assertCount(3, $this->rows());
    }

    public function test_the_allowlisted_name_and_email_may_hold_up_to_190_characters_and_pii_looking_values(): void
    {
        $a = $this->audit();
        $name = str_repeat('N', 189) . 'x';
        $email = str_repeat('e', 170) . '@ex.example';
        $a->log('admin.company_create', ['company_id' => 1, 'name' => $name]);
        $a->log('admin.company_create', ['company_id' => 1, 'name' => 'Müller & Söhne info@mueller.example']);
        $a->log('admin.user_add', ['company_id' => 1, 'user_id' => 2, 'email' => $email]);
        $rows = $this->rows();
        $this->assertCount(3, $rows);
        $this->assertSame($name, json_decode($rows[0]['detail'], true)['name']);
        $this->assertSame($email, json_decode($rows[2]['detail'], true)['email']);
    }

    public function test_other_keys_stay_at_80_characters_in_the_same_actions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->audit()->log('admin.user_add', ['company_id' => 1, 'role' => str_repeat('r', 81)]);
    }

    public function test_every_existing_event_keeps_its_stored_shape(): void
    {
        $events = [
            'auth.login_failed' => ['reason' => 'bad_password', 'key' => 'abcdef012345'],
            'auth.2fa_failed' => ['reason' => 'invalid'],
            'auth.password_ok' => [], 'auth.2fa_ok' => [], 'auth.backup_code_used' => [], 'auth.2fa_enabled' => [], 'auth.logout' => [],
            'security.backup_codes_regenerate' => [], 'security.password_change' => [],
            'company.switch' => ['company_id' => 3],
            'export.create' => ['include_pdfs' => true, 'year' => 2025, 'doc_count' => 4, 'pdf_count' => 3],
            'admin.company_create' => ['company_id' => 3, 'name' => 'Muster GmbH', 'plan' => 'basic', 'status' => 'active', 'until' => '2027-01-01'],
            'admin.access_change' => ['company_id' => 3, 'old_status' => 'active', 'new_status' => 'suspended', 'old_until' => null, 'new_until' => '2027-01-01'],
            'admin.user_add' => ['company_id' => 3, 'user_id' => 4, 'email' => 'a@b.example', 'role' => 'owner'],
            'admin.user_remove' => ['company_id' => 3, 'user_id' => 4],
            'admin.user_reset_2fa' => ['company_id' => 3, 'user_id' => 4],
            'document.issue' => ['document_id' => 5, 'type' => 'invoice', 'number' => 'RE-2026-0001', 'vat_confirmed' => true],
            'document.payment_add' => ['document_id' => 5, 'payment_id' => 6],
            'document.correction_create' => ['document_id' => 7, 'invoice_id' => 5],
            'document.offer_convert' => ['offer_id' => 8, 'invoice_id' => 9],
            'document.offer_status' => ['document_id' => 8, 'from' => 'open', 'to' => 'accepted'],
            'document.dunning_create' => ['document_id' => 10, 'invoice_id' => 5, 'level' => 2, 'interest' => true, 'flat_fee' => false, 'fee' => true],
            'document.email_send' => ['document_id' => 5, 'status' => 'failed', 'reason' => 'smtp_connect'],
            'settings.mail_test' => ['status' => 'sent'],
            'settings.numbers_save' => ['doc_type' => 'invoice', 'fields' => ['prefix', 'padding']],
            'settings.company_save' => ['fields' => ['name']], 'settings.dunning_save' => ['fields' => ['x']],
            'settings.mail_save' => ['fields' => ['smtp_host']], 'settings.logo_save' => ['fields' => ['logo_path']],
        ];
        $a = $this->audit();
        foreach ($events as $action => $detail) {
            $a->log($action, $detail);
        }
        foreach ($this->rows() as $row) {
            $expected = $events[$row['action']];
            $this->assertSame(json_encode($expected === [] ? new \stdClass() : $expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row['detail']);
        }
        $this->assertCount(count($events), $this->rows());
    }

    public function test_log_silently_drops_unknown_keys_and_pii_looking_values(): void
    {
        $this->audit()->logSilently('auth.login_failed', ['reason' => 'a@b.example', 'key' => '0123456789ab', 'remote_addr' => 'x', 'userEmail' => 'y']);
        $this->assertSame('{"key":"0123456789ab"}', $this->rows()[0]['detail']);
    }

    public function test_unicode_is_stored_readable_and_large_detail_is_truncated_by_dropping_keys(): void
    {
        $a = $this->audit();
        $a->log('auth.login_failed', ['reason' => 'zażółć']);
        $this->assertSame('{"reason":"zażółć"}', $this->rows()[0]['detail']);
        $names = [];
        for ($i = 0; $i < 60; $i++) {
            $names[] = str_pad((string) $i, 40, 'a');
        }
        $a->log('settings.company_save', ['fields' => $names]);
        $detail = (string) $this->rows()[1]['detail'];
        $this->assertLessThanOrEqual(2000, strlen($detail));
        $this->assertSame(['truncated' => true], json_decode($detail, true, 8, JSON_THROW_ON_ERROR));
    }

    public function test_purge_auth_removes_only_old_auth_rows(): void
    {
        $a = $this->audit();
        $day = 86_400;
        $a->log('auth.login_failed', ['reason' => 'locked'], null, null, $this->now - 91 * $day);
        $a->log('auth.password_ok', [], null, null, $this->now - 200 * $day);
        $a->log('auth.logout', [], null, null, $this->now - 89 * $day);
        $a->log('authx.keep', [], null, null, $this->now - 400 * $day);
        $a->log('document.issue', ['document_id' => 1], null, null, $this->now - 400 * $day);
        $this->assertSame(2, $a->purgeAuth(90));
        $this->assertSame(['auth.logout', 'authx.keep', 'document.issue'], array_column($this->rows(), 'action'));
        $this->assertSame(0, $a->purgeAuth(90));
    }

    public function test_log_propagates_a_write_failure_so_the_callers_transaction_rolls_back(): void
    {
        $this->db->exec('INSERT INTO companies (name) VALUES (?)', ['A']);
        $this->db->exec('DROP TABLE audit_log');
        $a = $this->audit();
        try {
            $this->db->transaction(function () use ($a): void {
                $this->db->exec("UPDATE companies SET name = 'Changed'");
                $a->log('company.switch', ['company_id' => 1]);
            });
            $this->fail('the failure must propagate');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('A', $this->db->one('SELECT name FROM companies')['name']);
    }

    public function test_log_silently_swallows_failures_without_leaking_details(): void
    {
        $this->db->exec('DROP TABLE audit_log');
        $log = tempnam(sys_get_temp_dir(), 'auditlog');
        $old = ini_set('error_log', $log);
        try {
            $this->audit()->logSilently('auth.login_failed', ['reason' => 'bad_password', 'key' => 'abcdef123456']);
        } finally {
            ini_set('error_log', (string) $old);
        }
        $out = (string) file_get_contents($log);
        unlink($log);
        $this->assertStringContainsString('audit', $out);
        $this->assertStringNotContainsString('abcdef123456', $out);
        $this->assertStringNotContainsString('bad_password', $out);
    }

    public function test_log_silently_drops_offending_keys_instead_of_throwing(): void
    {
        $this->audit()->logSilently('auth.login_failed', [
            'reason' => 'bad_password', 'email' => 'jan@example.pl', 'password' => 'x', 'long' => str_repeat('a', 200), 'nested' => [1],
        ]);
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('{"reason":"bad_password"}', $rows[0]['detail']);
        $this->assertStringNotContainsString('jan@example.pl', (string) $rows[0]['detail']);
    }

    public function test_log_silently_still_rejects_nothing_for_a_bad_action_but_never_throws(): void
    {
        $this->audit()->logSilently('', []);
        $this->assertSame([], $this->rows());
    }
}
