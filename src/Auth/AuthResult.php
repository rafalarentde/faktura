<?php
declare(strict_types=1);

namespace App\Auth;

/** Outcome of an AuthService step. */
final class AuthResult
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const LOCKED = 'locked';

    /** @param list<string> $backupCodes */
    private function __construct(
        public readonly string $status,
        public readonly ?int $userId = null,
        public readonly bool $totpEnabled = false,
        public readonly array $backupCodes = [],
    ) {
    }

    /** @param list<string> $backupCodes */
    public static function ok(int $userId, bool $totpEnabled = true, array $backupCodes = []): self
    {
        return new self(self::OK, $userId, $totpEnabled, $backupCodes);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    public static function locked(): self
    {
        return new self(self::LOCKED);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }
}
