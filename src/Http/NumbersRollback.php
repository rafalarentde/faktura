<?php
declare(strict_types=1);

namespace App\Http;

/** Thrown inside the numbering save transaction to roll back every row when one row is invalid. */
final class NumbersRollback extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Numbering save rolled back.');
    }
}
