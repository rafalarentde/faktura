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
        $this->audit()->log('document.issue', ['document_id' => 5, 'type' => 'invoice', 'ok' => true], 3, 7);
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('document.issue', $rows[0]['action']);
        $this->assertSame(3, (int) $rows[0]['company_id']);
        $this->assertSame(7, (int) $rows[0]['user_id']);
        $this->assertSame('2023-11-14 22:13:20', $rows[0]['at']);
        $this->assertSame('{"document_id":5,"type":"invoice","ok":true}', $rows[0]['detail']);
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

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function badDetails(): iterable
    {
        foreach (['password', 'new_password', 'pass', 'smtp_pass', 'secret', 'totp_secret', 'token', 'csrf_token', 'email', 'user_email', 'iban', 'bic',
            'body', 'subject', 'to', 'address', 'name', 'company_name', 'ip', 'Email'] as $key) {
            yield "key $key" => [[$key => 'x']];
        }
        yield 'long string' => [['reason' => str_repeat('a', 81)]];
        yield 'nested array' => [['document' => ['id' => 1]]];
        yield 'object' => [['x' => new \stdClass()]];
        yield 'fields with free text' => [['fields' => ['hello world, this is text']]];
        yield 'fields not a list of strings' => [['fields' => [1, 2]]];
        yield 'int key' => [[0 => 'x']];
    }

    /** @param array<string, mixed> $detail */
    #[\PHPUnit\Framework\Attributes\DataProvider('badDetails')]
    public function test_pii_guard_rejects_log(array $detail): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->audit()->log('x.y', $detail);
        } finally {
            $this->assertSame([], $this->rows(), 'nothing is written');
        }
    }

    public function test_safe_shapes_are_accepted(): void
    {
        $a = $this->audit();
        $a->log('settings.company_save', ['fields' => ['name', 'smtp_pass', 'address']]);
        $a->log('x.y', ['reason' => str_repeat('a', 80), 'n' => 5, 'f' => 1.5, 'b' => false, 'none' => null, 'number' => 'RE-2026/001']);
        $this->assertCount(2, $this->rows());
        $this->assertSame('{"fields":["name","smtp_pass","address"]}', $this->rows()[0]['detail']);
        $this->assertStringContainsString('RE-2026/001', $this->rows()[1]['detail'], 'slashes stay readable');
    }

    public function test_legacy_shapes_of_existing_events_are_allowed_only_for_those_actions(): void
    {
        $a = $this->audit();
        $a->log('admin.company_create', ['company_id' => 1, 'name' => 'Muster GmbH']);
        $a->log('admin.user_add', ['company_id' => 1, 'email' => 'a@b.example']);
        $a->log('document.offer_status', ['from' => 'open', 'to' => 'sent']);
        $this->assertCount(3, $this->rows());
        $this->expectException(\InvalidArgumentException::class);
        $a->log('other.event', ['name' => 'Muster GmbH']);
    }

    public function test_unicode_is_stored_readable_and_large_detail_is_truncated_by_dropping_keys(): void
    {
        $a = $this->audit();
        $a->log('x.y', ['reason' => 'zażółć']);
        $this->assertSame('{"reason":"zażółć"}', $this->rows()[0]['detail']);
        $big = [];
        for ($i = 0; $i < 60; $i++) {
            $big['key_' . $i] = str_repeat('v', 60);
        }
        $a->log('x.big', $big);
        $detail = (string) $this->rows()[1]['detail'];
        $this->assertLessThanOrEqual(2000, strlen($detail));
        $decoded = json_decode($detail, true, 8, JSON_THROW_ON_ERROR);
        $this->assertTrue($decoded['truncated']);
        $this->assertArrayHasKey('key_0', $decoded);
        $this->assertArrayNotHasKey('key_59', $decoded);
    }

    public function test_log_propagates_a_write_failure_so_the_callers_transaction_rolls_back(): void
    {
        $this->db->exec('INSERT INTO companies (name) VALUES (?)', ['A']);
        $this->db->exec('DROP TABLE audit_log');
        $a = $this->audit();
        try {
            $this->db->transaction(function () use ($a): void {
                $this->db->exec("UPDATE companies SET name = 'Changed'");
                $a->log('x.y', ['k' => 1]);
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
