<?php
declare(strict_types=1);

namespace App\Domain;

/** The document types that have their own number sequence. The one list of them. */
final class DocType
{
    public const INVOICE = 'invoice';
    public const OFFER = 'offer';
    public const DUNNING = 'dunning';
    public const CREDIT_NOTE = 'credit_note';

    /** @var list<string> display order */
    public const ALL = [self::INVOICE, self::OFFER, self::DUNNING, self::CREDIT_NOTE];

    private const DEFAULT_PATTERNS = [
        self::INVOICE => 'RE-{YYYY}-{NNN}',
        self::OFFER => 'AN-{YYYY}-{NNN}',
        self::DUNNING => 'MA-{YY}{MM}-{NNN}',
        self::CREDIT_NOTE => 'KO-{YYYY}-{NNN}',
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    /** @throws \InvalidArgumentException for anything outside the fixed list */
    public static function assertValid(string $type): string
    {
        if (!self::isValid($type)) {
            throw new \InvalidArgumentException('Unknown document type.');
        }
        return $type;
    }

    public static function defaultPattern(string $type): string
    {
        return self::DEFAULT_PATTERNS[self::assertValid($type)];
    }
}
