<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * A number pattern is not acceptable. `reason` is a stable code; the settings UI turns it into
 * the translation key `settings.err_num_<reason>`.
 */
final class InvalidNumberFormat extends \InvalidArgumentException
{
    public const NO_COUNTER = 'no_counter';
    public const MULTIPLE_COUNTERS = 'multiple_counters';
    public const UNKNOWN_TOKEN = 'unknown_token';
    public const UNBALANCED = 'unbalanced';
    public const TOO_SHORT = 'too_short';
    public const TOO_LONG = 'too_long';
    public const INVALID_CHAR = 'invalid_char';
    public const RENDERED_TOO_LONG = 'rendered_too_long';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Invalid number format: ' . $reason);
    }

    public function translationKey(): string
    {
        return 'settings.err_num_' . $this->reason;
    }
}
