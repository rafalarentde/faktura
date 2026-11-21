<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Audit;
use App\Core\Db;
use App\Repo\BaseRepo;
use App\Tenant\CompanyContext;

/**
 * Editable number formats and gap-free counters of the active company.
 *
 * Storage (see migrations/005_numbering.sql): one number_formats row per document type (absent =
 * the default pattern, reset yearly) and one number_counters row per period bucket. The bucket is
 * the Europe/Berlin calendar year when the format resets yearly, otherwise 0. All access goes
 * through the BaseRepo scoped helpers (reads that must see current data use scopedAll with
 * $lock), so company_id is bound to the context company in every statement; the document type
 * is validated against the fixed DocType list before it is used anywhere.
 *
 * next() is the only way a number is handed out. It is atomic: one `last_value = last_value + 1`
 * UPDATE (which also refuses to pass NumberFormat::MAX_COUNTER) inside Db::transaction, which
 * JOINS an already open transaction. The issue flow (Task 15) must call next() inside its own
 * transaction, so that a failed issue rolls the counter back and leaves no gap. The $d passed to
 * next()/preview()/lastCounter() is converted to Europe/Berlin here; year bucket and date tokens
 * always come from the Berlin date.
 *
 * INVARIANT: for one company and document type no two issued documents ever get the same rendered
 * number, however often reset_yearly is toggled. Strings can collide between bucket 0 (no reset)
 * and yearly bucket Y when they render the same year (the year token; a hand-typed literal year
 * in the pattern is the same thing without a token, see below). Every bucket is therefore raised
 * MONOTONICALLY (`UPDATE .. WHERE last_value < ?`, never lowered) to a floor before use:
 *  - bucket 0 (reset off), pattern WITH a year token: floor = last_value of the yearly bucket whose
 *    period equals the year of the date being issued (only that bucket renders the same strings);
 *    other years' buckets are ignored, so switching reset off never skips numbers silently;
 *  - bucket 0, pattern WITHOUT a year token (reset is forced off): floor = the MAXIMUM last_value
 *    over ALL yearly buckets of the type (a literal year such as RE-2026-{NNN} could repeat any
 *    year's numbers; no yearly restart is wanted there);
 *  - yearly bucket Y (reset on): floor = last_value of bucket 0 if bucket 0 has issued a number in
 *    year Y or later (counter row column last_year >= Y, kept monotonic), else 0. A bucket 0 last
 *    used in an earlier year cannot collide with year Y, so a normal yearly restart is untouched.
 * Skipping numbers by this rule is possible (conservative), repeating is not. preview() and
 * lastCounter() apply the same floors read-only, so the page shows the true next number.
 * configure() warns (literal_year) when the pattern contains a hand-typed 4-digit year. Residual
 * risk, covered by that warning only: bucket 0 used with a literal-year pattern in year Y and a
 * later switch to a {YYYY} pattern with reset on in year Y+1 can render that literal year again.
 *
 * KNOWN CORNER CASE (ruling R23, pinned by a test, deliberately NOT given a second counter path):
 * a company that used yearly reset, switched it OFF, and then issues a BACKDATED document into an
 * earlier year whose yearly bucket has a higher counter, raises bucket 0 to that counter. Example:
 * 2026 yearly bucket at 501, 2027 at 3, reset switched off in 2027: RE-2027-004 follows; a backdated
 * 2026-12-01 issue gives RE-2026-502 (bucket 0 raised to 501), and the next 2027 issue is
 * RE-2027-503. Numbers are skipped, never repeated; uniqueness wins over a gap-free look. It can
 * only happen after yearly reset was used and switched off, plus a backdated issue.
 *
 * CONCURRENCY (MySQL REPEATABLE READ; SQLite serializes writers anyway). Every write path (next,
 * configure) first takes the number_formats row of the company+type with a locking read (creating
 * it with the default pattern when missing) and reads the format AFTER that lock, so issues and
 * format/reset changes of one company+type are serialized and always see committed data. Lock
 * order: format row first, then the own bucket row, then the floor source rows (locking read,
 * ascending period); because the format lock serializes these paths, the bucket order cannot
 * deadlock among them. On MySQL the floor's range `FOR UPDATE` on (company_id, doc_type, period)
 * with `period <> ?` takes next-key locks that can include the first index record after the range
 * (another doc type's or company's first row), so their next() may wait until the issuing
 * transaction commits: contention only, no duplicate, no lock cycle between single-type
 * transactions. Plain SELECTs (a snapshot) are used only by the read-only preview()/lastCounter()/format().
 * All raises are monotonic conditional UPDATEs. A three-way race on the very first INSERT of a
 * format or bucket row can still end in MySQL deadlock 1213 for one of the requests: that
 * exception propagates, rolls its issue transaction back and can never produce a duplicate.
 *
 * DEPLOYMENT NOTE: migration 005 was edited in place (last_year column) because the code was
 * unreleased; a database that already ran an earlier 005 needs
 * `ALTER TABLE number_counters ADD COLUMN last_year BIGINT NOT NULL DEFAULT 0` first.
 *
 * Changing a pattern never resets a counter.
 */
final class NumberSequences extends BaseRepo
{
    private \Closure $clock;
    private ?Audit $audit = null;

    public function __construct(Db $db, CompanyContext $ctx, ?\Closure $clock = null)
    {
        parent::__construct($db, $ctx);
        $this->clock = $clock ?? time(...);
    }

    /** @return array{pattern: string, reset_yearly: bool} the stored format, or the default */
    public function format(string $docType): array
    {
        DocType::assertValid($docType);
        $row = $this->formatRow($docType);
        if ($row === null) {
            return ['pattern' => DocType::defaultPattern($docType), 'reset_yearly' => true];
        }
        return ['pattern' => (string) $row['pattern'], 'reset_yearly' => (int) $row['reset_yearly'] === 1];
    }

    /**
     * Saves the format and optionally the next number.
     *
     * - A pattern without exactly one counter (or otherwise invalid) throws InvalidNumberFormat.
     * - Yearly reset requested without a year token in the pattern: the format IS saved with
     *   reset_yearly forced off and the result carries warning reset_without_year.
     * - $nextValue must be >= last issued counter of the current bucket + 1 (ValidationException
     *   field next_value otherwise, also when a concurrent issue overtook it). Raising it skips
     *   numbers on purpose (note skips_numbers). It is stored as last_value = $nextValue - 1.
     * - Writes an audit row (settings.numbers_save: doc_type + changed field names) when
     *   something changed, in the same transaction.
     *
     * @throws InvalidNumberFormat|ValidationException|\InvalidArgumentException for an unknown doc type
     */
    public function configure(
        string $docType,
        string $pattern,
        bool $resetYearly,
        ?int $nextValue = null,
        ?\DateTimeImmutable $today = null,
    ): ConfigureResult {
        DocType::assertValid($docType);
        $parsed = NumberFormat::parse($pattern);
        $warnings = [];
        if ($resetYearly && !$parsed->hasYear()) {
            $resetYearly = false;
            $warnings[] = ConfigureResult::WARNING_RESET_WITHOUT_YEAR;
        }
        if ($parsed->hasLiteralYear()) {
            $warnings[] = ConfigureResult::WARNING_LITERAL_YEAR;
        }
        $today = $this->berlin($today ?? new \DateTimeImmutable('@' . ($this->clock)()));
        if ($nextValue !== null && ($nextValue < 1 || $nextValue > NumberFormat::MAX_COUNTER)) {
            throw new ValidationException(['next_value' => 'settings.err_next_range']);
        }

        return $this->db->transaction(function () use ($docType, $pattern, $resetYearly, $nextValue, $today, $warnings, $parsed): ConfigureResult {
            $row = $this->lockedFormat($docType);
            $old = ['pattern' => (string) $row['pattern'], 'reset_yearly' => (int) $row['reset_yearly'] === 1];
            $changed = [];
            if ($old['pattern'] !== $pattern) {
                $changed[] = 'pattern';
            }
            if ($old['reset_yearly'] !== $resetYearly) {
                $changed[] = 'reset_yearly';
            }
            $this->scopedUpdate('number_formats', (int) $row['id'], [
                'pattern' => $pattern,
                'reset_yearly' => $resetYearly ? 1 : 0,
                'updated_at' => ($this->clock)(),
            ]);

            $notes = [];
            if ($nextValue !== null) {
                $bucket = $this->activeBucket($docType, $resetYearly, (int) $today->format('Y'), $parsed->hasYear());
                $last = (int) $bucket['last_value'];
                if ($nextValue < $last + 1) {
                    throw new ValidationException(['next_value' => 'settings.err_next_low']);
                }
                if ($nextValue > $last + 1) {
                    // Conditional write: if an issue overtook us since the read, 0 rows change.
                    if ($this->scopedRaise('number_counters', (int) $bucket['id'], 'last_value', $nextValue - 1) !== 1) {
                        throw new ValidationException(['next_value' => 'settings.err_next_low']);
                    }
                    $notes[] = ConfigureResult::NOTE_SKIPS_NUMBERS;
                    $changed[] = 'next_number';
                }
            }
            if ($changed !== []) {
                $this->audit($docType, $changed);
            }
            return new ConfigureResult($pattern, $resetYearly, $warnings, $notes, $changed);
        });
    }

    /** The number next() would hand out now, WITHOUT consuming it. */
    public function preview(string $docType, \DateTimeImmutable $d): string
    {
        $d = $this->berlin($d);
        $fmt = $this->parsedFormat($docType);
        return $fmt['parsed']->render($d, $this->effectiveLast($docType, $fmt['reset'], (int) $d->format('Y'), $fmt['parsed']->hasYear()) + 1);
    }

    /** Last counter handed out (or reserved by a floor) in the bucket of $d; 0 when none yet. */
    public function lastCounter(string $docType, \DateTimeImmutable $d): int
    {
        $d = $this->berlin($d);
        $fmt = $this->parsedFormat($docType);
        return $this->effectiveLast($docType, $fmt['reset'], (int) $d->format('Y'), $fmt['parsed']->hasYear());
    }

    /**
     * Hands out the next number, atomically and without gaps (a rollback of the surrounding
     * transaction hands the same number out again). Never returns the same number twice for the
     * same company and type (see the class invariant).
     */
    public function next(string $docType, \DateTimeImmutable $d): string
    {
        DocType::assertValid($docType);
        $d = $this->berlin($d);
        $year = (int) $d->format('Y');
        return $this->db->transaction(function () use ($docType, $d, $year): string {
            // Format row first (lock order), then read the format from the locked, current row.
            $f = $this->lockedFormat($docType);
            $parsed = NumberFormat::parse((string) $f['pattern']);
            $id = (int) $this->activeBucket($docType, (int) $f['reset_yearly'] === 1, $year, $parsed->hasYear())['id'];
            if ($this->scopedIncrement('number_counters', $id, 'last_value', 1, NumberFormat::MAX_COUNTER) !== 1) {
                throw new \RuntimeException('Number range exhausted.'); // counter not moved
            }
            $this->scopedRaise('number_counters', $id, 'last_year', $year);
            // Read back inside the same transaction: the row is locked by our own UPDATE.
            $value = (int) ($this->scopedOne('number_counters', $id)['last_value'] ?? 0);
            return $parsed->render($d, $value);
        });
    }

    // ---- internals ----

    /** @return array{parsed: NumberFormat, reset: bool} */
    private function parsedFormat(string $docType): array
    {
        $f = $this->format($docType);
        return ['parsed' => NumberFormat::parse($f['pattern']), 'reset' => $f['reset_yearly']];
    }

    /** Business date: always Europe/Berlin, whatever zone the caller used. */
    private function berlin(\DateTimeImmutable $d): \DateTimeImmutable
    {
        return $d->setTimezone(new \DateTimeZone('Europe/Berlin'));
    }

    /** @return array<string, mixed>|null */
    private function formatRow(string $docType): ?array
    {
        return $this->scopedAll('number_formats', 'doc_type = ?', [$docType])[0] ?? null;
    }

    /**
     * Read-only twin of activeBucket(): the bucket's value with the floor applied, nothing written.
     */
    private function effectiveLast(string $docType, bool $reset, int $year, bool $hasYear): int
    {
        $period = $reset ? $year : 0;
        $own = $this->scopedAll('number_counters', 'doc_type = ? AND period = ?', [$docType, $period])[0] ?? null;
        return max($own === null ? 0 : (int) $own['last_value'], $this->floor($docType, $period, $year, false, $hasYear));
    }

    /**
     * Highest counter the bucket $period must not fall below (see class invariant). With $lock the
     * source rows are LISTED with a locking read, so rows committed by a concurrent transaction
     * after our snapshot are seen too.
     */
    private function floor(string $docType, int $period, int $year, bool $lock, bool $hasYear): int
    {
        $floor = 0;
        $rows = $this->scopedAll('number_counters', 'doc_type = ? AND period <> ?', [$docType, $period], 'period ASC', null, 0, $lock);
        foreach ($rows as $row) {
            $from = (int) $row['period'];
            if ($period === 0 ? (!$hasYear || $from === $year) : ($from === 0 && (int) $row['last_year'] >= $year)) {
                $floor = max($floor, (int) $row['last_value']);
            }
        }
        return $floor;
    }

    /**
     * The bucket row to issue from: created on first use, locked, and raised monotonically to its
     * floor. Returns the row as it is after that.
     *
     * @return array<string, mixed>
     */
    private function activeBucket(string $docType, bool $reset, int $year, bool $hasYear): array
    {
        $period = $reset ? $year : 0;
        $row = $this->insertOrFetch(
            'number_counters',
            'doc_type = ? AND period = ?',
            [$docType, $period],
            ['doc_type' => $docType, 'period' => $period, 'last_value' => 0, 'last_year' => 0],
        );
        $floor = $this->floor($docType, $period, $year, true, $hasYear);
        if ($floor > (int) $row['last_value']) {
            $this->scopedRaise('number_counters', (int) $row['id'], 'last_value', $floor);
            $row['last_value'] = $floor;
        }
        return $row;
    }

    /**
     * The locked number_formats row of the type (default pattern, reset on, when missing). Every
     * write path calls this FIRST.
     *
     * @return array<string, mixed>
     */
    private function lockedFormat(string $docType): array
    {
        return $this->insertOrFetch('number_formats', 'doc_type = ?', [$docType], [
            'doc_type' => $docType,
            'pattern' => DocType::defaultPattern($docType),
            'reset_yearly' => 1,
            'updated_at' => ($this->clock)(),
        ]);
    }

    /**
     * Returns the locked row matching $where, inserting $row first when there is none. Race-safe:
     * if a concurrent request inserts the same unique key first, the INSERT fails with an
     * integrity-constraint error (SQLSTATE class 23 only; deadlocks and everything else
     * propagate, because the transaction is then already lost) and we use the winner's row via a
     * LOCKING read (a plain SELECT would still show the old snapshot under REPEATABLE READ). When
     * no winner row can be found the original constraint error is rethrown.
     *
     * @param list<scalar> $params
     * @param array<string, scalar> $row
     * @return array<string, mixed>
     */
    private function insertOrFetch(string $table, string $where, array $params, array $row): array
    {
        $caught = null;
        if (($this->scopedAll($table, $where, $params)[0] ?? null) === null) {
            try {
                $this->scopedInsert($table, $row);
            } catch (\PDOException $e) {
                if (!self::isConstraintViolation($e)) {
                    throw $e;
                }
                $caught = $e;
            }
        }
        return $this->scopedAll($table, $where, $params, '', 1, 0, true)[0]
            ?? throw ($caught ?? new \RuntimeException('Row vanished.'));
    }

    private static function isConstraintViolation(\PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        return str_starts_with($state, '23');
    }

    /** @param list<string> $fields */
    private function audit(string $docType, array $fields): void
    {
        $this->audit ??= new Audit($this->db, $this->clock);
        $this->audit->log('settings.numbers_save', ['doc_type' => $docType, 'fields' => $fields], $this->ctx->id(), $this->ctx->userId());
    }
}
