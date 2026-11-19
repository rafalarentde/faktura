<?php
declare(strict_types=1);

namespace App\Domain;

/** The company may not issue documents (suspended, or trial expired). Reading and drafts stay allowed. */
final class AccessSuspended extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Issuing is suspended for this company.');
    }
}
