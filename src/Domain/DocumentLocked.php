<?php
declare(strict_types=1);

namespace App\Domain;

/** The document is issued (or otherwise final) and can no longer be changed or deleted. */
final class DocumentLocked extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Document is locked.');
    }
}
