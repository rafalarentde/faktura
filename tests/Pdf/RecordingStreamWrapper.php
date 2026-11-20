<?php
declare(strict_types=1);

namespace Tests\Pdf;

/**
 * Stand-in for the http/https stream wrappers while PDFs are rendered: records every URL that
 * something tries to open (into PdfRendererTest::$fetched) and fails the open, so a remote fetch
 * by Dompdf would be both visible to the test and harmless.
 */
final class RecordingStreamWrapper
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        PdfRendererTest::$fetched[] = $path;
        return false;
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        PdfRendererTest::$fetched[] = $path;
        return false;
    }
}
