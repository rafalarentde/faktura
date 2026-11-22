<?php
declare(strict_types=1);

namespace App\Domain;

/** The statutory flat fee (§ 288 Abs. 5 BGB) may only be charged to businesses, not consumers. */
final class FlatFeeNotAllowed extends \DomainException
{
}
