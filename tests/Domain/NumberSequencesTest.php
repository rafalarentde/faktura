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
use Tests\Support\HookPdo;

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

    // ---- reset_yearly toggles: no rendered number may ever repeat ----

    /** @return list<string> */
    private function issue(NumberSequences $s, int $n, string $date, string $type = 'invoice'): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $s->next($type, $this->d($date));
        }
        return $out;
    }

    private function setReset(NumberSequences $s, bool $on, string $date, string $pattern = 'RE-{YYYY}-{NNN}'): void
    {
        $s->configure('invoice', $pattern, $on, null, $this->d($date));
    }

    public function test_toggle_on_off_on(): void
    {
        $s = $this->seq($this->company());
        $d = '2026-05-01';
        $got = $this->issue($s, 2, $d);
        $this->setReset($s, false, $d);
        $this->assertSame('RE-2026-003', $s->preview('invoice', $this->d($d)));
        $got = [...$got, ...$this->issue($s, 2, $d)];
        $this->setReset($s, true, $d);
        $this->assertSame('RE-2026-005', $s->preview('invoice', $this->d($d)), 'preview shows the true next number');
        $got = [...$got, ...$this->issue($s, 1, $d)];
        $this->assertSame(['RE-2026-001', 'RE-2026-002', 'RE-2026-003', 'RE-2026-004', 'RE-2026-005'], $got);
    }

    public function test_toggle_off_first_then_on(): void
    {
        $s = $this->seq($this->company());
        $d = '2026-05-01';
        $this->setReset($s, false, $d);
        $got = $this->issue($s, 2, $d);
        $this->setReset($s, true, $d);
        $got = [...$got, ...$this->issue($s, 1, $d)];
        $this->assertSame(['RE-2026-001', 'RE-2026-002', 'RE-2026-003'], $got);
    }

    public function test_toggle_on_off_on_off(): void
    {
        $s = $this->seq($this->company());
        $d = '2026-05-01';
        $got = $this->issue($s, 2, $d);
        $this->setReset($s, false, $d);
        $got = [...$got, ...$this->issue($s, 2, $d)];
        $this->setReset($s, true, $d);
        $got = [...$got, ...$this->issue($s, 1, $d)];
        $this->setReset($s, false, $d);
        $got = [...$got, ...$this->issue($s, 1, $d)];
        $this->assertSame(['RE-2026-001', 'RE-2026-002', 'RE-2026-003', 'RE-2026-004', 'RE-2026-005', 'RE-2026-006'], $got);
    }

    public function test_toggle_on_after_a_year_change_without_reset(): void
    {
        $s = $this->seq($this->company());
        $got = $this->issue($s, 2, '2026-05-01');
        $this->setReset($s, false, '2026-05-01');
        $got = [...$got, ...$this->issue($s, 1, '2027-01-02')]; // RE-2027-003 from bucket 0
        $this->setReset($s, true, '2027-01-02');
        $got = [...$got, ...$this->issue($s, 2, '2027-01-02')];
        $this->assertSame(['RE-2026-001', 'RE-2026-002', 'RE-2027-003', 'RE-2027-004', 'RE-2027-005'], $got);
    }

    public function test_yearly_restart_is_kept_when_the_non_yearly_bucket_was_last_used_in_an_earlier_year(): void
    {
        $s = $this->seq($this->company());
        $this->setReset($s, false, '2026-05-01');
        $this->issue($s, 3, '2026-05-01');
        $this->setReset($s, true, '2027-01-02');
        $this->assertSame(['RE-2027-001', 'RE-2027-002'], $this->issue($s, 2, '2027-01-02'), 'a new year starts again at 1');
    }

    public function test_toggle_with_a_year_less_pattern_keeps_reset_off_and_uses_bucket_zero_only(): void
    {
        $s = $this->seq($this->company());
        $this->setReset($s, true, '2026-05-01', 'INV-{NNN}'); // warning, forced off
        $this->assertSame(['INV-001', 'INV-002'], $this->issue($s, 2, '2026-05-01'));
        $this->setReset($s, true, '2027-03-01', 'INV-{NNN}');
        $this->assertSame(['INV-003'], $this->issue($s, 1, '2027-03-01'));
        $this->assertSame([0], array_map(fn (array $r): int => (int) $r['period'], $this->db->all("SELECT period FROM number_counters WHERE doc_type = 'invoice' ORDER BY period")));
    }

    public function test_year_less_pattern_after_yearly_use_continues_from_the_highest_counter(): void
    {
        $s = $this->seq($this->company());
        $this->issue($s, 4, '2026-05-01');
        $this->setReset($s, false, '2026-05-01', 'INV-{NNN}');
        $this->assertSame('INV-005', $s->next('invoice', $this->d('2026-05-01')));
    }

    public function test_random_toggles_year_changes_and_issues_never_repeat(): void
    {
        mt_srand(20261118);
        for ($round = 0; $round < 5; $round++) {
            $db = \TestDb::fresh();
            $userId = (new UserService($db))->create("r$round@example.com", 'correct horse battery', false);
            $db->exec('INSERT INTO companies (name) VALUES (?)', ['R']);
            $cid = $db->lastId();
            $db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$cid, $userId, 'owner']);
            $s = new NumberSequences($db, CompanyContext::forUser($db, $userId, $cid), fn (): int => 1_700_000_000);
            $year = 2026;
            $reset = true;
            $seen = [];
            $lastPerYear = [];
            for ($i = 0; $i < 120; $i++) {
                $date = $this->d("$year-06-15");
                switch (mt_rand(0, 5)) {
                    case 0:
                        $reset = !$reset;
                        $s->configure('invoice', 'RE-{YYYY}-{NNN}', $reset, null, $date);
                        break;
                    case 1:
                        $year++;
                        break;
                    case 2:
                        $s->preview('invoice', $date);
                        break;
                    default:
                        $number = $s->next('invoice', $date);
                        $this->assertArrayNotHasKey($number, $seen, "repeated $number (round $round, step $i)");
                        $seen[$number] = true;
                        $counter = (int) substr($number, -3);
                        $this->assertGreaterThan($lastPerYear[$year] ?? 0, $counter, "not increasing within $year");
                        $lastPerYear[$year] = $counter;
                }
            }
            $this->assertNotEmpty($seen);
        }
    }

    // ---- race handling ----

    /** @return array{0: Db, 1: HookPdo, 2: NumberSequences, 3: int} */
    private function hooked(): array
    {
        $pdo = new HookPdo('sqlite::memory:');
        $db = new Db($pdo, 'sqlite');
        (new \App\Core\Migrator($db, __DIR__ . '/../../migrations'))->run();
        $userId = (new UserService($db))->create('h@example.com', 'correct horse battery', false);
        $db->exec('INSERT INTO companies (name) VALUES (?)', ['H']);
        $cid = $db->lastId();
        $db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$cid, $userId, 'owner']);
        return [$db, $pdo, new NumberSequences($db, CompanyContext::forUser($db, $userId, $cid), fn (): int => 1_700_000_000), $cid];
    }

    public function test_lost_bucket_creation_race_falls_back_to_the_winners_row(): void
    {
        [$db, $pdo, $s, $cid] = $this->hooked();
        $fired = 0;
        $pdo->onPrepare = function (string $sql, \PDO $pdo) use (&$fired, $cid): void {
            // another request inserts the same bucket right before our INSERT runs
            if ($fired === 0 && str_starts_with($sql, 'INSERT INTO `number_counters`')) {
                $fired++;
                $pdo->exec("INSERT INTO number_counters (company_id, doc_type, period, last_value, last_year) VALUES ($cid, 'invoice', 2026, 41, 2026)");
            }
        };
        $this->assertSame('RE-2026-042', $s->next('invoice', $this->d('2026-05-01')), 'continues from the winner row, no failure');
        $this->assertSame(1, $fired);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) AS c FROM number_counters')['c']);
    }

    public function test_lost_format_creation_race_falls_back_to_the_winners_row(): void
    {
        [$db, $pdo, $s, $cid] = $this->hooked();
        $fired = 0;
        $pdo->onPrepare = function (string $sql, \PDO $pdo) use (&$fired, $cid): void {
            if ($fired === 0 && str_starts_with($sql, 'INSERT INTO `number_formats`')) {
                $fired++;
                $pdo->exec("INSERT INTO number_formats (company_id, doc_type, pattern, reset_yearly, updated_at) VALUES ($cid, 'invoice', 'OLD-{N}', 0, 1)");
            }
        };
        $s->configure('invoice', 'NEW-{NNN}', false, null, $this->d('2026-05-01'));
        $this->assertSame(1, $fired);
        $this->assertSame('NEW-{NNN}', $s->format('invoice')['pattern']);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) AS c FROM number_formats')['c']);
    }

    public function test_non_constraint_errors_propagate_unchanged_and_consume_nothing(): void
    {
        [$db, $pdo, $s] = $this->hooked();
        $error = new \PDOException('Deadlock found when trying to get lock');
        $error->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
        $pdo->onPrepare = function (string $sql) use ($error): void {
            if (str_starts_with($sql, 'INSERT INTO `number_counters`')) {
                throw $error;
            }
        };
        try {
            $s->next('invoice', $this->d('2026-05-01'));
            $this->fail('Expected the deadlock to propagate');
        } catch (\PDOException $e) {
            $this->assertSame($error, $e);
        }
        $pdo->onPrepare = null;
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) AS c FROM number_counters')['c']);
        $this->assertSame('RE-2026-001', $s->next('invoice', $this->d('2026-05-01')));
    }

    public function test_raising_the_next_number_loses_no_concurrent_issue(): void
    {
        [$db, $pdo, $s, $cid] = $this->hooked();
        $d = $this->d('2026-05-01');
        $this->issue($s, 5, '2026-05-01');
        $fired = 0;
        $pdo->onPrepare = function (string $sql, \PDO $pdo) use (&$fired, $cid): void {
            // two issues commit (6 and 7) between configure's read and its write
            if ($fired === 0 && str_starts_with($sql, 'UPDATE `number_counters` SET `last_value` = ? WHERE')) {
                $fired++;
                $pdo->exec("UPDATE number_counters SET last_value = 7 WHERE company_id = $cid AND doc_type = 'invoice'");
            }
        };
        // the concurrent bump shares this connection, so keep it by catching inside an outer transaction
        $db->transaction(function () use ($s, $d): void {
            try {
                $s->configure('invoice', 'RE-{YYYY}-{NNN}', true, 7, $d);
                $this->fail('Expected ValidationException');
            } catch (ValidationException $e) {
                $this->assertSame(['next_value' => 'settings.err_next_low'], $e->errors);
            }
        });
        $this->assertSame(1, $fired);
        $this->assertSame(7, $s->lastCounter('invoice', $d), 'the counter was not lowered');
        $this->assertSame('RE-2026-008', $s->next('invoice', $d));
    }

    // ---- Berlin business date ----

    public function test_year_bucket_and_tokens_come_from_berlin_whatever_zone_is_passed(): void
    {
        $s = $this->seq($this->company());
        $utc = new \DateTimeImmutable('2026-12-31 23:30:00', new \DateTimeZone('UTC')); // 2027-01-01 00:30 Berlin
        $this->assertSame('RE-2027-001', $s->preview('invoice', $utc));
        $this->assertSame('RE-2027-001', $s->next('invoice', $utc));
        $this->assertSame('RE-2027-002', $s->next('invoice', $this->d('2027-01-01')));
        $this->assertSame(2, $s->lastCounter('invoice', $utc));
        $ny = new \DateTimeImmutable('2026-12-31 20:00:00', new \DateTimeZone('America/New_York')); // 2027-01-01 02:00 Berlin
        $this->assertSame('RE-2027-003', $s->next('invoice', $ny));
    }

    public function test_leap_day(): void
    {
        $s = $this->seq($this->company());
        $s->configure('invoice', '{YYYY}{MM}{DD}-{NN}', true, null, $this->d('2028-02-29'));
        $this->assertSame('20280229-01', $s->next('invoice', new \DateTimeImmutable('2028-02-29 12:00:00', new \DateTimeZone('UTC'))));
        $this->assertSame('20280301-02', $s->next('invoice', new \DateTimeImmutable('2028-02-29 23:30:00', new \DateTimeZone('UTC'))), '23:30 UTC is already March 1st in Berlin');
    }

    public function test_exhausted_range_never_moves_the_counter(): void
    {
        $s = $this->seq($this->company());
        $d = $this->d('2026-11-18');
        $s->configure('invoice', '{N}', false, 9_999_999_999, $d);
        $s->next('invoice', $d);
        for ($i = 0; $i < 3; $i++) {
            try {
                $s->next('invoice', $d);
                $this->fail('Expected RuntimeException');
            } catch (\RuntimeException) {
            }
        }
        $this->assertSame(9_999_999_999, (int) $this->db->one('SELECT last_value AS v FROM number_counters')['v']);
    }
}
