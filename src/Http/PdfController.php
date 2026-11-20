<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Domain\DocumentNotFound;
use App\Pdf\PdfNotAvailable;

/**
 * GET /documents/{id}/pdf (Task 17): the PDF of an issued invoice or credit note of the active
 * company, inline (default) or as a download (?download=1).
 *
 * - Unknown ids, other companies' documents and types without a PDF yet (offers, dunning letters)
 *   give the same 404 page; a draft gives a 409 page. No PDF bytes in either case.
 * - The file name is the document number reduced to [A-Za-z0-9._-] (PdfRenderer::filename), so
 *   no quote, CR/LF or path separator from a number format can reach the header.
 * - Cache-Control: no-store comes from the kernel defaults; nosniff is set explicitly.
 */
final class PdfController
{
    public function __construct(private App $app)
    {
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        try {
            $file = $this->app->pdf()->renderFile($id);
        } catch (DocumentNotFound) {
            return $this->error(404, 'error.not_found');
        } catch (PdfNotAvailable) {
            return $this->error(409, 'pdf.err_draft');
        }
        $disposition = $request->queryParam('download') === '1' ? 'attachment' : 'inline';
        return new Response($file['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition . '; filename="' . $file['filename'] . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function error(int $status, string $key): Response
    {
        return $this->app->render('error.twig', ['status' => $status, 'message' => $this->app->t($key), 'detail' => null], $status);
    }
}
