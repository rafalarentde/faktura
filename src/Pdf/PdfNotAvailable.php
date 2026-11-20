<?php
declare(strict_types=1);

namespace App\Pdf;

/** The document exists (for the active company) but has no PDF yet: it is still a draft (HTTP 409). */
final class PdfNotAvailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only issued documents have a PDF.');
    }
}
