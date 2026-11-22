<?php
declare(strict_types=1);

namespace App\Pdf;

/** The archived PDF of a document does not match its recorded SHA-256 (integrity failure; HTTP 500, nothing is overwritten). */
final class ArchiveCorrupt extends \RuntimeException
{
    public function __construct(int $documentId)
    {
        parent::__construct('The archived PDF of document ' . $documentId . ' does not match its recorded checksum.');
    }
}
