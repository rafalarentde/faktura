<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Issue refused: the buyer is an EU company whose VAT id is not confirmed by VIES. The user must
 * explicitly confirm issuing WITH German VAT (issue(..., confirmUnverifiedVat: true)); reverse
 * charge is never applied silently.
 */
final class VatNeedsConfirmation extends \RuntimeException
{
    public function __construct(public readonly VatDecision $decision)
    {
        parent::__construct('VAT treatment needs confirmation.');
    }
}
