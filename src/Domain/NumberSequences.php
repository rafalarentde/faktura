<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;
use App\Repo\BaseRepo;
use App\Tenant\CompanyContext;

/**
 * Editable number formats and gap-free counters of the active company.
 *
 * Storage (see migrations/005_numbering.sql): one number_formats row per document type (absent =
 * the default pattern, reset yearly) and one number_counters row per period bucket. The bucket is
 * the calendar year when the format resets yearly, otherwise 0. All access goes through the
 * BaseRepo scoped helpers, so company_id is bound to the context company in every statement; the
 * document type is validated against the fixed DocType list before it is used anywhere.
 *
 * next() is the only way a number is handed out. It is atomic: one `last_value = last_value + 1`
 * UPDATE inside Db::transaction, which JOINS an already open transaction. The issue flow (Task 15)
 * must call next() inside its own transaction, so that a failed issue rolls the counter back and
 * leaves no gap. The $d passed to next()/preview()/lastCounter() must be the Europe/Berlin
 * business date (year bucket and date tokens come from it).
 *
 * Changing a pattern never resets a counter. When yearly reset is switched OFF, the single
 * non-yearly bucket (0) starts at the highest counter ever issued for the type, so a number that
 * already exists cannot be issued again.
 */
final class NumberSequences extends BaseRepo
{
    private \Closure $clock;

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
     *   field next_value otherwise). Raising it skips numbers on purpose (note skips_numbers).
     *   It is stored as last_value = $nextValue - 1.
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
        $today ??= $this->berlinNow();
        if ($nextValue !== null && ($nextValue < 1 || $nextValue > NumberFormat::MAX_COUNTER)) {
            throw new ValidationException(['next_value' => 'settings.err_next_range']);
        }

        return $this->db->transaction(function () use ($docType, $pattern, $resetYearly, $nextValue, $today, $warnings): ConfigureResult {
            $old = $this->format($docType);
            $changed = [];
            if ($old['pattern'] !== $pattern) {
                $changed[] = 'pattern';
            }
            if ($old['reset_yearly'] !== $resetYearly) {
                $changed[] = 'reset_yearly';
            }
            $this->saveFormat($docType, $pattern, $resetYearly);

            $notes = [];
            if ($nextValue !== null) {
                $period = $this->period($resetYearly, $today);
                $id = $this->ensureBucket($docType, $period);
                $last = (int) $this->scopedOne('number_counters', $id)['last_value'];
                if ($nextValue < $last + 1) {
                    throw new ValidationException(['next_value' => 'settings.err_next_low']);
                }
                if ($nextValue > $last + 1) {
                    $this->scopedUpdate('number_counters', $id, ['last_value' => $nextValue - 1]);
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
        $fmt = $this->parsedFormat($docType);
        return $fmt['parsed']->render($d, $this->peekLast($docType, $this->period($fmt['reset'], $d)) + 1);
    }

    /** Last counter handed out in the bucket of $d (0 when none yet). */
    public function lastCounter(string $docType, \DateTimeImmutable $d): int
    {
        $reset = $this->format($docType)['reset_yearly'];
        return $this->peekLast($docType, $this->period($reset, $d));
    }

    /**
     * Hands out the next number, atomically and without gaps (a rollback of the surrounding
     * transaction hands the same number out again). Never returns the same number twice for the
     * same company, type and bucket.
     */
    public function next(string $docType, \DateTimeImmutable $d): string
    {
        $fmt = $this->parsedFormat($docType);
        $period = $this->period($fmt['reset'], $d);
        return $this->db->transaction(function () use ($docType, $period, $fmt, $d): string {
            $id = $this->ensureBucket($docType, $period);
            if ($this->scopedIncrement('number_counters', $id, 'last_value') !== 1) {
                throw new \RuntimeException('Counter row vanished.');
            }
            // Read back inside the same transaction: the row is locked by our own UPDATE.
            $value = (int) ($this->scopedOne('number_counters', $id)['last_value'] ?? 0);
            if ($value > NumberFormat::MAX_COUNTER) {
                throw new \RuntimeException('Number range exhausted.');
            }
            return $fmt['parsed']->render($d, $value);
        });
    }

    // ---- internals ----

    /** @return array{parsed: NumberFormat, reset: bool} */
    private function parsedFormat(string $docType): array
    {
        $f = $this->format($docType);
        return ['parsed' => NumberFormat::parse($f['pattern']), 'reset' => $f['reset_yearly']];
    }

    private function period(bool $resetYearly, \DateTimeImmutable $d): int
    {
        return $resetYearly ? (int) $d->format('Y') : 0;
    }

    private function berlinNow(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . ($this->clock)()))->setTimezone(new \DateTimeZone('Europe/Berlin'));
    }

    /** @return array<string, mixed>|null */
    private function formatRow(string $docType): ?array
    {
        return $this->scopedAll('number_formats', 'doc_type = ?', [$docType])[0] ?? null;
    }

    /** Counter of a bucket without creating it (see class docblock for the non-yearly seed). */
    private function peekLast(string $docType, int $period): int
    {
        $row = $this->bucketRow($docType, $period);
        return $row !== null ? (int) $row['last_value'] : $this->initialValue($docType, $period);
    }

    /** @return array<string, mixed>|null */
    private function bucketRow(string $docType, int $period): ?array
    {
        return $this->scopedAll('number_counters', 'doc_type = ? AND period = ?', [$docType, $period])[0] ?? null;
    }

    /** Start value of a new bucket: 0, except the non-yearly bucket continues from the highest issued counter. */
    private function initialValue(string $docType, int $period): int
    {
        if ($period !== 0) {
            return 0;
        }
        $max = 0;
        foreach ($this->scopedAll('number_counters', 'doc_type = ?', [$docType]) as $row) {
            $max = max($max, (int) $row['last_value']);
        }
        return $max;
    }

    /** Id of the bucket row, created on first use. Race-safe: the unique index decides. */
    private function ensureBucket(string $docType, int $period): int
    {
        $row = $this->bucketRow($docType, $period);
        if ($row === null) {
            try {
                return $this->scopedInsert('number_counters', [
                    'doc_type' => $docType,
                    'period' => $period,
                    'last_value' => $this->initialValue($docType, $period),
                ]);
            } catch (\PDOException $e) {
                // A concurrent request created the same bucket first: use its row.
                $row = $this->bucketRow($docType, $period) ?? throw $e;
            }
        }
        return (int) $row['id'];
    }

    private function saveFormat(string $docType, string $pattern, bool $resetYearly): void
    {
        $values = ['pattern' => $pattern, 'reset_yearly' => $resetYearly ? 1 : 0, 'updated_at' => ($this->clock)()];
        $row = $this->formatRow($docType);
        if ($row === null) {
            try {
                $this->scopedInsert('number_formats', ['doc_type' => $docType] + $values);
                return;
            } catch (\PDOException $e) {
                $row = $this->formatRow($docType) ?? throw $e;
            }
        }
        $this->scopedUpdate('number_formats', (int) $row['id'], $values);
    }

    /** @param list<string> $fields */
    private function audit(string $docType, array $fields): void
    {
        $this->db->exec(
            'INSERT INTO audit_log (company_id, user_id, action, detail, at) VALUES (?, ?, ?, ?, ?)',
            [
                $this->ctx->id(),
                $this->ctx->userId(),
                'settings.numbers_save',
                json_encode(['doc_type' => $docType, 'fields' => $fields], JSON_THROW_ON_ERROR),
                gmdate('Y-m-d H:i:s', ($this->clock)()),
            ]
        );
    }
}
