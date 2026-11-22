<?php
declare(strict_types=1);

namespace App\Domain;

/** Outcome of NumberSequences::configure(). */
final class ConfigureResult
{
    public const WARNING_RESET_WITHOUT_YEAR = 'reset_without_year';
    public const WARNING_LITERAL_YEAR = 'literal_year';
    public const NOTE_SKIPS_NUMBERS = 'skips_numbers';

    /**
     * @param list<string> $warnings WARNING_* codes (the format was saved, but not as asked)
     * @param list<string> $notes NOTE_* codes (informational)
     * @param list<string> $changed names of the changed fields: pattern, reset_yearly, next_number
     */
    public function __construct(
        public readonly string $pattern,
        public readonly bool $resetYearly,
        public readonly array $warnings,
        public readonly array $notes,
        public readonly array $changed,
    ) {
    }
}
