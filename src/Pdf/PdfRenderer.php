<?php
declare(strict_types=1);

namespace App\Pdf;

use App\Domain\DocType;
use App\Domain\DocumentNotFound;
use App\Domain\ValidationException;
use App\Http\DocumentPresenter;
use App\Repo\DocumentRepo;
use App\Storage\LogoStore;
use App\Tenant\CompanyContext;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Twig\Environment;

/**
 * Server-side PDF of ISSUED invoices and credit notes of the active company (Task 17).
 *
 * Data: only the document's stored snapshots, lines and totals through DocumentPresenter::issued()
 * (document language for every label and number). The one live asset is the company's current
 * logo, re-encoded by LogoStore::process() and embedded as a data: URI (no file access by Dompdf).
 *
 * Access: render()/html() throw DocumentNotFound for unknown ids, other companies' documents and
 * types without a PDF yet (offers and dunning letters come with Tasks 19/21), and PdfNotAvailable
 * for drafts.
 *
 * Dompdf is locked down: no remote resources, no PHP, no JavaScript, only the data:// and file://
 * protocols, chroot = the resources directory (fonts). All user strings are escaped by Twig
 * autoescape before Dompdf sees the HTML. Fonts: the static TTF faces in resources/fonts (FONTS);
 * Dompdf's font metrics, font cache and temp files live in $cacheDir (storage/pdf-cache, created
 * on first use; must be writable on the host).
 */
final class PdfRenderer
{
    /**
     * Every face Dompdf may use, mapped to its static TTF in resources/fonts. Italic slots point
     * at the upright files so no style can ever fall back to a core PDF font without Polish glyphs.
     */
    public const FONTS = [
        ['family' => 'sourcesans3', 'weight' => 'normal', 'style' => 'normal', 'file' => 'SourceSans3-Regular.ttf'],
        ['family' => 'sourcesans3', 'weight' => 'normal', 'style' => 'italic', 'file' => 'SourceSans3-Regular.ttf'],
        ['family' => 'sourcesans3', 'weight' => 'bold', 'style' => 'normal', 'file' => 'SourceSans3-Bold.ttf'],
        ['family' => 'sourcesans3', 'weight' => 'bold', 'style' => 'italic', 'file' => 'SourceSans3-Bold.ttf'],
        ['family' => 'barlowcondensed', 'weight' => 'normal', 'style' => 'normal', 'file' => 'BarlowCondensed-Bold.ttf'],
        ['family' => 'barlowcondensed', 'weight' => 'bold', 'style' => 'normal', 'file' => 'BarlowCondensed-Bold.ttf'],
        ['family' => 'barlowcondensed', 'weight' => 'normal', 'style' => 'italic', 'file' => 'BarlowCondensed-Bold.ttf'],
        ['family' => 'barlowcondensed', 'weight' => 'bold', 'style' => 'italic', 'file' => 'BarlowCondensed-Bold.ttf'],
        ['family' => 'barlowcondensedxb', 'weight' => 'normal', 'style' => 'normal', 'file' => 'BarlowCondensed-ExtraBold.ttf'],
        ['family' => 'barlowcondensedxb', 'weight' => 'bold', 'style' => 'normal', 'file' => 'BarlowCondensed-ExtraBold.ttf'],
        ['family' => 'barlowcondensedxb', 'weight' => 'normal', 'style' => 'italic', 'file' => 'BarlowCondensed-ExtraBold.ttf'],
        ['family' => 'barlowcondensedxb', 'weight' => 'bold', 'style' => 'italic', 'file' => 'BarlowCondensed-ExtraBold.ttf'],
    ];
    public const BODY_FONT = 'sourcesans3';

    private const TEMPLATES = [DocType::INVOICE => 'pdf/invoice.twig', DocType::CREDIT_NOTE => 'pdf/invoice.twig'];
    private const MM = 72 / 25.4;
    // Page number position (points from the top-left corner of an A4 page): right margin, below the footer.
    private const PAGE_NO_RIGHT = 595.28 - 18 * self::MM;
    private const PAGE_NO_TOP = 841.89 - 7 * self::MM;
    private const LOGO_BOX = [62.0, 20.0]; // mm
    private const FONT_HEIGHT_RATIO = 0.72;

    private string $resourcesDir;

    public function __construct(
        private Environment $twig,
        private DocumentPresenter $presenter,
        private DocumentRepo $docs,
        private LogoStore $logos,
        private CompanyContext $ctx,
        string $resourcesDir,
        private string $cacheDir,
    ) {
        $this->resourcesDir = realpath($resourcesDir) ?: throw new \InvalidArgumentException('Resources directory missing.');
    }

    /**
     * PDF bytes of an issued invoice or credit note of the active company.
     *
     * @throws DocumentNotFound unknown id, another company's document, a type without PDF
     * @throws PdfNotAvailable the document is a draft
     */
    public function render(int $documentId): string
    {
        return $this->renderFile($documentId)['content'];
    }

    /**
     * The PDF with its download file name (PdfRenderer::filename of the document number), from
     * one read of the document.
     *
     * @return array{content: string, filename: string}
     * @throws DocumentNotFound|PdfNotAvailable as render()
     */
    public function renderFile(int $documentId): array
    {
        [$doc, $vm] = $this->load($documentId);
        $dompdf = new Dompdf($this->options());
        $metrics = $dompdf->getFontMetrics();
        foreach (self::FONTS as $f) {
            $file = $this->resourcesDir . '/fonts/' . $f['file'];
            if (!$metrics->registerFont(['family' => $f['family'], 'weight' => $f['weight'], 'style' => $f['style']], $file)) {
                throw new \RuntimeException('Cannot register PDF font ' . $f['file'] . ' (is the PDF cache directory writable?)');
            }
        }
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($this->htmlFor($doc, $vm), 'UTF-8');
        $dompdf->render();

        $font = $metrics->getFont(self::BODY_FONT, 'normal');
        // Every page names the document ("RE-2026-001 · Seite 2 von 3"), also a totals-only page.
        $label = $doc['number'] . ' · ' . $vm['labels']['page'];
        $dompdf->getCanvas()->page_script(
            function (int $page, int $pages, Canvas $canvas, FontMetrics $fm) use ($font, $label): void {
                $text = strtr($label, ['{page}' => (string) $page, '{pages}' => (string) $pages]);
                $size = 7.5;
                $canvas->text(self::PAGE_NO_RIGHT - $fm->getTextWidth($text, $font, $size), self::PAGE_NO_TOP, $text, $font, $size, [0.4, 0.4, 0.4]);
            }
        );
        $dompdf->addInfo('Creator', 'Faktura');
        // Reproducible dates: the moment of issue, not the moment of rendering.
        $issued = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $doc['issued_at'], new \DateTimeZone('UTC'));
        if ($issued !== false) {
            $dompdf->addInfo('CreationDate', 'D:' . $issued->format('YmdHis') . "+00'00'");
            $dompdf->addInfo('ModDate', 'D:' . $issued->format('YmdHis') . "+00'00'");
        }
        return ['content' => (string) $dompdf->output(), 'filename' => self::filename((string) $doc['number'])];
    }

    /**
     * The HTML that render() hands to Dompdf (for tests and debugging; never served to browsers).
     *
     * @throws DocumentNotFound|PdfNotAvailable as render()
     */
    public function html(int $documentId): string
    {
        [$doc, $vm] = $this->load($documentId);
        return $this->htmlFor($doc, $vm);
    }

    /** The locked-down Dompdf options (see the class comment). */
    public function options(): Options
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException('Cannot create the PDF cache directory ' . $this->cacheDir);
        }
        $o = new Options();
        $o->setIsRemoteEnabled(false);
        $o->setIsPhpEnabled(false);
        $o->setIsJavascriptEnabled(false);
        $o->setIsHtml5ParserEnabled(true);
        $o->setAllowedProtocols(['data://', 'file://']);
        $o->setChroot([$this->resourcesDir]);
        $o->setFontDir($this->cacheDir);
        $o->setFontCache($this->cacheDir);
        $o->setTempDir($this->cacheDir);
        $o->setDefaultFont(self::BODY_FONT);
        $o->setDefaultPaperSize('a4');
        $o->setDpi(96);
        // Dompdf multiplies CSS line-height by (ascender - descender) x this ratio. Source Sans 3
        // has 1.424 em between ascender and descender, so 0.72 makes line-height ~em based.
        $o->setFontHeightRatio(self::FONT_HEIGHT_RATIO);
        return $o;
    }

    /** Download file name: the document number with everything outside [A-Za-z0-9._-] replaced by "_". */
    public static function filename(string $number): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]/u', '_', mb_substr($number, 0, 100));
        return ($safe === '' ? 'document' : $safe) . '.pdf';
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the document row and its view model
     */
    private function load(int $documentId): array
    {
        $doc = $this->docs->find($documentId);
        if ($doc === null || !isset(self::TEMPLATES[$doc['type']])) {
            throw new DocumentNotFound();
        }
        if ($doc['status'] !== 'issued') {
            throw new PdfNotAvailable();
        }
        return [$doc, $this->presenter->issued($doc)];
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $vm
     */
    private function htmlFor(array $doc, array $vm): string
    {
        return $this->twig->render(self::TEMPLATES[$doc['type']], ['doc' => $vm, 'logo' => $this->logo()]);
    }

    /**
     * The company's current logo as a data: URI with its display size in mm (fitted into
     * LOGO_BOX), re-encoded from the stored file; null when there is none or it is unreadable.
     *
     * @return array{src: string, width: string, height: string}|null
     */
    private function logo(): ?array
    {
        $file = $this->logos->locate($this->ctx->id(), $this->ctx->company()['logo_path'] ?? null);
        if ($file === null) {
            return null;
        }
        try {
            $clean = $this->logos->process($file);
        } catch (ValidationException) {
            return null;
        }
        $size = getimagesizefromstring($clean['bytes']);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }
        $scale = min(self::LOGO_BOX[0] / $size[0], self::LOGO_BOX[1] / $size[1]);
        return [
            'src' => 'data:' . ($clean['ext'] === 'png' ? 'image/png' : 'image/jpeg') . ';base64,' . base64_encode($clean['bytes']),
            'width' => number_format($size[0] * $scale, 2, '.', ''),
            'height' => number_format($size[1] * $scale, 2, '.', ''),
        ];
    }
}
