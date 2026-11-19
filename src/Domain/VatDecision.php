<?php
declare(strict_types=1);

namespace App\Domain;

/** Outcome of VatRule::decide(). */
final class VatDecision
{
    public function __construct(
        public readonly bool $reverseCharge,
        public readonly bool $needsConfirmation,
        public readonly ?string $noteKey,
        public readonly string $reason,
    ) {
    }
}
