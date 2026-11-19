<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Auth\UserService;
use App\Core\Db;
use App\Domain\ConfigureResult;
use App\Domain\DocType;
use App\Domain\InvalidNumberFormat;
use App\Domain\NumberSequences;
use App\Domain\ValidationException;
use App\Tenant\CompanyContext;
use PHPUnit\Framework\TestCase;

final class NumberSequencesTest extends TestCase
{
    private Db $db;
    private int $userId;

    protected function setUp(): void
    {
        $this->db = \TestDb::fresh();
        $this->userId = (new UserService($this->db))->create('o@example.com', 'correct horse battery', false);
    }

    private function company(string $name = 'Muster GmbH'): int
    {
        $this->db->exec('INSERT INTO companies (name) VALUES (?)', [$name]);
        $id = $this->db->lastId();
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$id, $this->userId, 'owner']);
        return $id;
    }

    private function seq(int $companyId, ?Db $db = null): NumberSequences
    {
        $db ??= $this->db;
        return new NumberSequences($db, CompanyContext::forUser($db, $this->userId, $companyId), fn (): int => 1_700_000_000);
    }

    private function d(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Berlin'));
    }

    public function test_defaults_when_nothing_is_configured(): void
    {
        $s = $this->seq($this->company());
        $this->assertSame(['pattern' => 'RE-{YYYY}-{NNN}', 'reset_yearly' => true], $s->format(DocType::INVOICE));
        $this->assertSame('RE-2026-001', $s->preview('invoice', $this->d('2026-11-18')));
        $this->assertSame('AN-2026-001', $s->preview('offer', $this->d('2026-11-18')));
        $this->assertSame('MA-2611-001', $s->preview('dunning', $this->d('2026-11-18')));
        $this->assertSame('KO-2026-001', $s->preview('credit_note', $this->d('2026-11-18')));
    }

    public function test_unknown_doc_type_is_refused_everywhere(): void
    {
        $s = $this->seq($this->company());
        foreach (['', 'invoices', "invoice' OR '1'='1", 'INVOICE', 'x'] as $bad) {
            foreach ([
                fn () => $s->format($bad),
                fn () => $s->preview($bad, $this->d('2026-01-01')),
                fn () => $s->next($bad, $this->d('2026-01-01')),
                fn () => $s->lastCounter($bad, $this->d('2026-01-01')),
                fn () => $s->configure($bad, 'X-{N}', false),
            ] as $call) {
                try {
                    $call();
                    $this->fail("Accepted doc type '$bad'");
                } catch (\InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS c FROM number_counters')['c']);
    }

    public function test_preview_does_not_consume(): void
    {
        $s = $this->seq($this->company());
        $d = $this->d('2026-11-18');
        $this->assertSame('RE-2026-001', $s->preview('invoice', $d));
        $this->assertSame('RE-2026-001', $s->preview('invoice', $d));
        $this->assertSame(0, $s->lastCounter('invoice', $d));
        $this->assertSame('RE-2026-001', $s->next('invoice', $d));
        $this->assertSame('RE-2026-002', $s->preview('invoice', $d));
        $this->assertSame(1, $s->lastCounter('invoice', $d));
    }

    public function test_two_consecutive_next_calls_never_repeat(): void
    {
        $s = $this->seq($this->company());
        $d = $this->d('2026-11-18');
        $this->assertSame('RE-2026-001', $s->next('invoice', $d));
        $this->assertSame('RE-2026-002', $s->next('invoice', $d));
    }

    public function test_200_calls_are_unique_and_strictly_increasing(): void
    {
        $s = $this->seq($this->company());
        $d = $this->d('2026-11-18');
        $s->configure('invoice', '{N}', false);
        $seen = [];
        $prev = 0;
        for ($i = 0; $i < 200; $i++) {
            $n = (int) $s->next('invoice', $d);
            $this->assertGreaterThan($prev, $n);
            $this->assertArrayNotHasKey($n, $seen);
            $seen[$n] = true;
            $prev = $n;
        }
        $this->assertSame(200, $prev);
        $this->assertSame(200, $s->lastCounter('invoice', $d));
    }

    public function test_yearly_reset_restarts_counter_in_new_year(): void
    {
        $s = $this->seq($this->company());
        $s->next('invoice', $this->d('2026-12-31'));
        $this->assertSame('RE-2026-002', $s->next('invoice', $this->d('2026-12-31')));
        $this->assertSame('RE-2027-001', $s->next('invoice', $this->d('2027-01-01')));
        $this->assertSame('RE-2027-002', $s->next('invoice', $this->d('2027-01-02')));
        $this->assertSame(2, $s->lastCounter('invoice', $this->d('2026-06-01')));
    }

    public function test_no_reset_continues_across_years(): void
    {
        $s = $this->seq($this->company());
        $s->configure('invoice', 'RE-{YYYY}-{NNN}', false);
        $this->assertSame('RE-2026-001', $s->next('invoice', $this->d('2026-12-31')));
        $this->assertSame('RE-2027-002', $s->next('invoice', $this->d('2027-01-01')));
    }

    public function test_switching_reset_off_continues_from_the_highest_issued_counter(): void
    {
        $s = $this->seq($this->company());
        $d = $this->d('2026-05-01');
        $s->next('invoice', $d);
        $s->next('invoice', $d);
        $s->next('invoice', $d); // 2026: 3 issued
        $s->configure('invoice', 'RE-{YYYY}-{NNN}', false, null, $d);
        // never RE-2026-001 again
        $this->assertSame('RE-2026-004', $s->next('invoice', $d));
    }

    public function test_changing_the_pattern_does_not_reset_the_counter(): void
    {
        $s = $this->seq($this->company());
        $d = $this->d('2026-05-01');
        $s->next('invoice', $d);
        $s->next('invoice', $d);
        $s->configure('invoice', 'FV/{YY}/{MM}/{NNNN}', true, null, $d);
        $this->assertSame('FV/26/05/0003', $s->next('invoice', $d));
    }

    public function test_reset_without_year_is_saved_with_warning_and_reset_forced_off(): void
    {
        $s = $this->seq($this->company());
        $r = $s->configure('invoice', 'INV-{NNNN}', true);
        $this->assertSame([ConfigureResult::WARNING_RESET_WITHOUT_YEAR], $r->warnings);
        $this->assertFalse($r->resetYearly);
        $this->assertSame(['pattern' => 'INV-{NNNN}', 'reset_yearly' => false], $s->format('invoice'));
        $this->assertSame('INV-0001', $s->next('invoice', $this->d('2026-12-31')));
        $this->assertSame('INV-0002', $s->next('invoice', $this->d('2027-01-01')));
    }

    public function test_reset_with_year_has_no_warning(): void
    {
        $r = $this->seq($this->company())->configure('invoice', 'INV-{YY}-{NNNN}', true);
        $this->assertSame([], $r->warnings);
        $this->assertTrue($r->resetYearly);
    }

    public function test_configure_rejects_invalid_patterns_and_saves_nothing(): void
    {
        $s = $this->seq($this->company());
        foreach (['FAKTURA', '{N}{NN}', '{yyyy}-{N}', '{N'] as $bad) {
            try {
                $s->configure('invoice', $bad, true);
                $this->fail('Accepted ' . $bad);
            } catch (InvalidNumberFormat) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('RE-{YYYY}-{NNN}', $s->format('invoice')['pattern']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS c FROM number_formats')['c']);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) AS c FROM audit_log WHERE action = 'settings.numbers_save'")['c']);
    }

    public function test_next_value_can_be_raised_and_skips_numbers(): void
    {
        $s = $this->seq($this->company());
        $d = $this->d('2026-11-18');
        $s->next('invoice', $d); // 1 issued
        $r = $s->configure('invoice', 'RE-{YYYY}-{NNN}', true, 100, $d);
        $this->assertSame([ConfigureResult::NOTE_SKIPS_NUMBERS], $r->notes);
        $this->assertSame(99, $s->lastCounter('invoice', $d)); // last_value = next - 1
        $this->assertSame('RE-2026-100', $s->preview('invoice', $d));
        $this->assertSame('RE-2026-100', $s->next('invoice', $d));
    }

    public function test_next_value_equal_to_the_current_next_is_fine_and_has_no_note(): void
    {
        $s = $this->seq($this->company());
        $d = $this->d('2026-11-18');
        $s->next('invoice', $d);
        $r = $s->configure('invoice', 'RE-{YYYY}-{NNN}', true, 2, $d);
        $this->assertSame([], $r->notes);
        $this->assertSame(1, $s->lastCounter('invoice', $d));
    }

    public function test_next_value_cannot_go_below_issued_and_nothing_is_saved(): void
    {
        $s = $this->seq($this->company());
        $d = $this->d('2026-11-18');
        $s->next('invoice', $d);
        $s->next('invoice', $d);
        $s->next('invoice', $d); // last = 3
        try {
            $s->configure('invoice', 'ZZ-{NNN}', true, 3, $d);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['next_value' => 'settings.err_next_low'], $e->errors);
        }
        $this->assertSame('RE-{YYYY}-{NNN}', $s->format('invoice')['pattern'], 'format change rolled back with the failed next value');
        $this->assertSame(3, $s->lastCounter('invoice', $d));
    }

    public function test_next_value_range(): void
    {
        $s = $this->seq($this->company());
        foreach ([0, -5, 10_000_000_000] as $bad) {
            try {
                $s->configure('invoice', 'RE-{YYYY}-{NNN}', true, $bad);
                $this->fail('Accepted ' . $bad);
            } catch (ValidationException $e) {
                $this->assertSame(['next_value' => 'settings.err_next_range'], $e->errors);
            }
        }
    }

    public function test_next_value_applies_to_the_bucket_of_the_given_day(): void
    {
        $s = $this->seq($this->company());
        $s->next('invoice', $this->d('2026-12-31'));
        $s->configure('invoice', 'RE-{YYYY}-{NNN}', true, 50, $this->d('2027-01-02'));
        $this->assertSame('RE-2027-050', $s->next('invoice', $this->d('2027-01-02')));
        $this->assertSame(1, $s->lastCounter('invoice', $this->d('2026-12-31')));
    }

    public function test_sequences_are_separate_per_company_and_type(): void
    {
        $a = $this->company('A');
        $b = $this->company('B');
        $sa = $this->seq($a);
        $sb = $this->seq($b);
        $d = $this->d('2026-11-18');
        $this->assertSame('RE-2026-001', $sa->next('invoice', $d));
        $this->assertSame('RE-2026-002', $sa->next('invoice', $d));
        $this->assertSame('AN-2026-001', $sa->next('offer', $d));
        $this->assertSame('RE-2026-001', $sb->next('invoice', $d));
        $this->assertSame('KO-2026-001', $sb->next('credit_note', $d));
        $this->assertSame('MA-2611-001', $sa->next('dunning', $d));
        $this->assertSame(2, $sa->lastCounter('invoice', $d));
        $this->assertSame(1, $sb->lastCounter('invoice', $d));
    }

    public function test_formats_and_configuration_are_isolated_between_companies(): void
    {
        $a = $this->company('A');
        $b = $this->company('B');
        $sa = $this->seq($a);
        $sb = $this->seq($b);
        $d = $this->d('2026-11-18');
        $sb->configure('invoice', 'B-{NNNNN}', false, 700, $d);
        $sa->configure('invoice', 'A-{N}', false, null, $d);
        $this->assertSame('B-{NNNNN}', $sb->format('invoice')['pattern']);
        $this->assertSame('A-{N}', $sa->format('invoice')['pattern']);
        $this->assertSame('B-00700', $sb->preview('invoice', $d));
        $this->assertSame('A-1', $sa->preview('invoice', $d));
        $this->assertSame(699, $sb->lastCounter('invoice', $d));
        $this->assertSame(0, $sa->lastCounter('invoice', $d));
        // every stored row carries its own company
        $this->assertSame(2, (int) $this->db->one('SELECT COUNT(*) AS c FROM number_formats')['c']);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) AS c FROM number_formats WHERE company_id = ?', [$a])['c']);
        // A's activity never changed B's counter
        $sa->next('invoice', $d);
        $this->assertSame(699, $sb->lastCounter('invoice', $d));
    }

    public function test_configure_writes_audit_with_field_names_only(): void
    {
        $c = $this->company();
        $s = $this->seq($c);
        $d = $this->d('2026-11-18');
        $s->configure('offer', 'ANGEBOT-SECRET-{NNN}', false, 55, $d);
        $row = $this->db->one("SELECT * FROM audit_log WHERE action = 'settings.numbers_save'");
        $this->assertSame($c, (int) $row['company_id']);
        $this->assertSame($this->userId, (int) $row['user_id']);
        $detail = json_decode((string) $row['detail'], true);
        $this->assertSame('offer', $detail['doc_type']);
        $this->assertEqualsCanonicalizing(['pattern', 'reset_yearly', 'next_number'], $detail['fields']);
        $this->assertStringNotContainsString('SECRET', (string) $row['detail']);
        $this->assertStringNotContainsString('55', (string) $row['detail']);
        // saving the same thing again changes nothing: no new audit row
        $s->configure('offer', 'ANGEBOT-SECRET-{NNN}', false, 55, $d);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) AS c FROM audit_log WHERE action = 'settings.numbers_save'")['c']);
    }

    public function test_rolled_back_transaction_leaves_no_gap(): void
    {
        $s = $this->seq($this->company());
        $d = $this->d('2026-11-18');
        $this->assertSame('RE-2026-001', $s->next('invoice', $d));

        // the issue flow: next() inside an outer transaction that fails afterwards
        try {
            $this->db->transaction(function () use ($s, $d): void {
                $this->assertSame('RE-2026-002', $s->next('invoice', $d));
                throw new \RuntimeException('issue failed');
            });
            $this->fail('Expected exception');
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, $s->lastCounter('invoice', $d));
        $this->assertSame('RE-2026-002', $s->next('invoice', $d), 'the rolled-back number is handed out again');
    }

    public function test_rollback_of_the_first_use_of_a_bucket_leaves_no_row(): void
    {
        $s = $this->seq($this->company());
        $d = $this->d('2026-11-18');
        try {
            $this->db->transaction(function () use ($s, $d): void {
                $s->next('invoice', $d);
                throw new \RuntimeException('x');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS c FROM number_counters')['c']);
        $this->assertSame('RE-2026-001', $s->next('invoice', $d));
    }

    public function test_interleaved_instances_on_the_same_db_never_repeat_committed_numbers(): void
    {
        $c = $this->company();
        $s1 = $this->seq($c);
        $s2 = $this->seq($c); // a second repo/request on the same connection
        $d = $this->d('2026-11-18');
        $committed = [];

        $committed[] = $s1->next('invoice', $d);
        try {
            $this->db->transaction(function () use ($s1, $s2, $d): void {
                $s1->next('invoice', $d); // 002, will be rolled back
                $s2->next('invoice', $d); // 003 inside the same outer transaction
                throw new \RuntimeException('rollback both');
            });
        } catch (\RuntimeException) {
        }
        $committed[] = $s2->next('invoice', $d);
        $committed[] = $s1->next('invoice', $d);
        $committed[] = $s2->next('invoice', $d);
        $this->db->transaction(function () use ($s1, $s2, $d, &$committed): void {
            $committed[] = $s1->next('invoice', $d);
            $committed[] = $s2->next('invoice', $d);
        });
        $committed[] = $s1->next('invoice', $d);

        $this->assertSame(
            ['RE-2026-001', 'RE-2026-002', 'RE-2026-003', 'RE-2026-004', 'RE-2026-005', 'RE-2026-006', 'RE-2026-007'],
            $committed
        );
        $this->assertSame(count($committed), count(array_unique($committed)));
    }

    public function test_unique_index_prevents_a_second_bucket_row(): void
    {
        // A concurrent INSERT of an existing bucket must fail on the unique index; ensureBucket then
        // falls back to the existing row. Here: the duplicate insert itself is refused.
        $c = $this->company();
        $s = $this->seq($c);
        $d = $this->d('2026-11-18');
        $s->next('invoice', $d);
        $this->expectException(\PDOException::class);
        $this->db->exec('INSERT INTO number_counters (company_id, doc_type, period, last_value) VALUES (?, ?, ?, ?)', [$c, 'invoice', 2026, 0]);
    }

    public function test_exhausted_range_throws_and_does_not_consume(): void
    {
        $s = $this->seq($this->company());
        $d = $this->d('2026-11-18');
        $s->configure('invoice', '{N}', false, 9_999_999_999, $d);
        $this->assertSame('9999999999', $s->next('invoice', $d));
        try {
            $s->next('invoice', $d);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }
        $this->assertSame(9_999_999_999, $s->lastCounter('invoice', $d));
    }
}
