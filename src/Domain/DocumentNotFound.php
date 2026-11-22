<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Unknown document id, or one of another company: both look exactly the same (static message,
 * the id is never echoed). Controllers map it to 404.
 */
final class DocumentNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Document not found.');
    }
}
