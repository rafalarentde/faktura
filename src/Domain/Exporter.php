<?php
declare(strict_types=1);

namespace App\Domain;

use App\I18n\Translator;
use App\Repo\ExportRepo;
use App\Tenant\CompanyContext;

/**
 * Complete export of the ACTIVE company as a ZIP file (temp file, mode 0600, random name under
 * the tmp directory). It works for a suspended company too: nothing here consults the access
 * status. All reads go through ExportRepo (company-scoped); no secret is ever selected.
 *
 * Layout: README.txt, company.json, users.json (id, email, role, ui_lang only), customers.json,
 * items.json (items with names), documents.json (every document incl. drafts, with lines,
 * payments, decoded seller/buyer snapshots and all totals in integer minor units),
 * email_log.json, numbering.json, invoices.csv (Excel-friendly, see csv()) and, unless switched
 * off, pdf/<number>.pdf for every ISSUED invoice, credit note, offer and dunning letter.
 *
 * Options: include_pdfs (bool, default true), year (null = everything incl. drafts, or a 4-digit
 * year = issued documents of that year, no drafts; customers/items/users/numbering stay complete),
 * lang (README language, default de). Limits: at most $maxPdfs PDFs (more is a ValidationException
 * telling the user to pick a year or untick the PDFs); documents are read in pages of PAGE rows and
 * written to files, never kept as one list. A failure removes every temp file it created.
 *
 * Seam for tests: $renderPdf (document id => ['content' => bytes, 'filename' => name]) replaces the
 * PdfRenderer; $maxPdfs lowers the limit.
 */
final class Exporter
{
    public const MAX_PDFS = 400;
    private const PAGE = 200;
    /** Leftover temp files (a download that never finished) older than this are removed. */
    private const STALE_SECONDS = 3600;
    private const CSV_HEADER = ['Nummer', 'Typ', 'Datum', 'Fällig', 'Kunde', 'Währung', 'Netto', 'USt', 'Brutto', 'Bezahlt', 'Offen', 'Status'];

    private \Closure $clock;
    /** @var array{doc_count: int, pdf_count: int} */
    private array $stats = ['doc_count' => 0, 'pdf_count' => 0];

    /**
     * @param \Closure(int): array{content: string, filename: string} $renderPdf
     * @param \Closure|null $clock unix time
     */
    public function __construct(
        private CompanyContext $ctx,
        private ExportRepo $repo,
        private Translator $translator,
        private \Closure $renderPdf,
        private string $tmpDir,
        ?\Closure $clock = null,
        private int $maxPdfs = self::MAX_PDFS,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** Counts of the last build(): documents written to documents.json and PDFs added. @return array{doc_count: int, pdf_count: int} */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * Validates the options without building anything (the controller calls it before it spends
     * rate-limit budget).
     *
     * @param array<string, mixed> $options as build()
     * @throws ValidationException year (export.err_year) or pdfs (export.err_too_many_pdfs)
     */
    public function check(array $options = []): void
    {
        $year = $this->year($options['year'] ?? null);
        if (($options['include_pdfs'] ?? true) === true && $this->repo->countPdfDocuments($year) > $this->maxPdfs) {
            throw new ValidationException(['pdfs' => 'export.err_too_many_pdfs']);
        }
    }

    /**
     * Builds the ZIP and returns its path. The caller must delete the file after use.
     *
     * @param array<string, mixed> $options include_pdfs, year, lang
     * @throws ValidationException year (export.err_year) or pdfs (export.err_too_many_pdfs)
     */
    public function build(array $options = []): string
    {
        $this->check($options);
        $includePdfs = ($options['include_pdfs'] ?? true) === true;
        $year = $this->year($options['year'] ?? null);
        $lang = (string) ($options['lang'] ?? 'de');
        if (function_exists('set_time_limit')) {
            try {
                @set_time_limit(300);
            } catch (\Throwable) {
                // disabled or not permitted: the export just runs under the current limit
            }
        }
        $this->stats = ['doc_count' => 0, 'pdf_count' => 0];

        $oldUmask = umask(0077);
        $work = null;
        $zipPath = null;
        try {
            $this->ensureTmpDir();
            $this->purgeStale();
            $id = bin2hex(random_bytes(12));
            $work = $this->tmpDir . '/export-' . $id;
            $zipPath = $this->tmpDir . '/export-' . $id . '.zip';
            if (!mkdir($work, 0700)) {
                throw new \RuntimeException('Cannot create the export work directory.');
            }
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
                throw new \RuntimeException('Cannot create the export archive.');
            }
            $company = $this->repo->company();
            $this->json($zip, $work, 'company.json', $company);
            $this->json($zip, $work, 'users.json', $this->repo->members());
            $this->paged($zip, $work, 'customers.json', fn (int $after): array => $this->repo->customers($after, self::PAGE));
            $this->items($zip, $work);
            $this->json($zip, $work, 'numbering.json', ['formats' => $this->repo->numberFormats(), 'counters' => $this->repo->numberCounters()]);
            $this->documents($zip, $work, $year, $includePdfs);
            $zip->addFromString('README.txt', $this->readme((string) ($company['name'] ?? ''), $lang, $year, $includePdfs));
            if (!$zip->close()) {
                throw new \RuntimeException('Cannot finish the export archive.');
            }
            chmod($zipPath, 0600);
            return $zipPath;
        } catch (\Throwable $e) {
            if ($zipPath !== null) {
                @unlink($zipPath);
            }
            throw $e;
        } finally {
            if ($work !== null) {
                $this->removeTree($work);
            }
            umask($oldUmask);
        }
    }

    /** @return int|null */
    private function year(mixed $year): ?int
    {
        if ($year === null || $year === '') {
            return null;
        }
        if ((is_int($year) || is_string($year)) && preg_match('/\A[0-9]{4}\z/', (string) $year) === 1 && (int) $year >= 1970) {
            return (int) $year;
        }
        throw new ValidationException(['year' => 'export.err_year']);
    }

    /** @param array<array-key, mixed> $data */
    private function json(\ZipArchive $zip, string $work, string $name, array $data): void
    {
        $path = $work . '/' . $name;
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT;
        file_put_contents($path, json_encode($data, $flags) . "\n");
        $zip->addFile($path, $name);
    }

    /** @param \Closure(int): list<array<string, mixed>> $fetch rows after an id */
    private function paged(\ZipArchive $zip, string $work, string $name, \Closure $fetch): void
    {
        $out = new JsonArrayFile($work . '/' . $name);
        $after = 0;
        while (($rows = $fetch($after)) !== []) {
            foreach ($rows as $r) {
                $out->add($r);
                $after = (int) $r['id'];
            }
        }
        $zip->addFile($out->close(), $name);
    }

    private function items(\ZipArchive $zip, string $work): void
    {
        $out = new JsonArrayFile($work . '/items.json');
        $after = 0;
        while (($rows = $this->repo->items($after, self::PAGE)) !== []) {
            $names = [];
            foreach ($this->repo->itemNames(array_map(fn (array $r): int => (int) $r['id'], $rows)) as $n) {
                $names[(int) $n['item_id']][] = $n;
            }
            foreach ($rows as $r) {
                $r['names'] = $names[(int) $r['id']] ?? [];
                $out->add($r);
                $after = (int) $r['id'];
            }
        }
        $zip->addFile($out->close(), 'items.json');
    }

    private function documents(\ZipArchive $zip, string $work, ?int $year, bool $includePdfs): void
    {
        $docs = new JsonArrayFile($work . '/documents.json');
        $mails = new JsonArrayFile($work . '/email_log.json');
        $csvPath = $work . '/invoices.csv';
        $csv = fopen($csvPath, 'wb');
        if ($csv === false) {
            throw new \RuntimeException('Cannot write export file.');
        }
        fwrite($csv, "\xEF\xBB\xBF" . $this->csvLine(self::CSV_HEADER, []));
        $today = (new \DateTimeImmutable('@' . ($this->clock)()))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
        $usedNames = [];
        $pdfCount = 0;
        $after = 0;
        try {
            while (($page = $this->repo->documents($year, $after, self::PAGE)) !== []) {
                $ids = array_map(fn (array $d): int => (int) $d['id'], $page);
                $lines = $payments = $mailRows = [];
                foreach ($this->repo->lines($ids) as $l) {
                    $lines[(int) $l['document_id']][] = $l;
                }
                foreach ($this->repo->payments($ids) as $p) {
                    $payments[(int) $p['document_id']][] = $p;
                }
                foreach ($this->repo->emailLog($ids) as $m) {
                    $mailRows[] = $m;
                }
                $invoiceIds = array_values(array_filter($ids, fn (int $i): bool => $this->typeOf($page, $i) === DocType::INVOICE));
                $credited = $this->repo->creditedByInvoice($invoiceIds);
                foreach ($page as $d) {
                    $docId = (int) $d['id'];
                    $after = $docId;
                    $d['seller'] = $this->decode($d['seller_json'] ?? null);
                    $d['buyer'] = $this->decode($d['buyer_json'] ?? null);
                    unset($d['seller_json'], $d['buyer_json']);
                    $d['lines'] = $lines[$docId] ?? [];
                    $d['payments'] = $payments[$docId] ?? [];
                    $docs->add($d);
                    if ($d['status'] === 'issued' && in_array($d['type'], [DocType::INVOICE, DocType::CREDIT_NOTE], true)) {
                        fwrite($csv, $this->csvRow($d, $d['payments'], $credited[$docId] ?? 0, $today));
                    }
                    if ($includePdfs && $d['status'] === 'issued' && DocType::isValid((string) $d['type'])) {
                        $pdf = ($this->renderPdf)($docId);
                        $name = $this->uniqueName($usedNames, (string) $d['type'], $docId, $pdf['filename']);
                        $tmp = $work . '/pdf-' . $docId . '.bin';
                        file_put_contents($tmp, $pdf['content']);
                        $zip->addFile($tmp, 'pdf/' . $name);
                        $pdfCount++;
                    }
                }
                foreach ($mailRows as $m) {
                    $mails->add($m);
                }
                $this->stats['doc_count'] += count($page);
            }
        } finally {
            fclose($csv);
        }
        $this->stats['pdf_count'] = $pdfCount;
        $zip->addFile($docs->close(), 'documents.json');
        $zip->addFile($mails->close(), 'email_log.json');
        $zip->addFile($csvPath, 'invoices.csv');
    }

    /** @param list<array<string, mixed>> $page */
    private function typeOf(array $page, int $id): string
    {
        foreach ($page as $d) {
            if ((int) $d['id'] === $id) {
                return (string) $d['type'];
            }
        }
        return '';
    }

    /** @return array<string, mixed>|null */
    private function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $v = json_decode($json, true);
        return is_array($v) ? $v : null;
    }

    /** @param array<string, true> $used */
    private function uniqueName(array &$used, string $type, int $id, string $filename): string
    {
        foreach ([$filename, $type . '-' . $filename, $type . '-' . $id . '-' . $filename] as $candidate) {
            if (!isset($used[strtolower($candidate)])) {
                $used[strtolower($candidate)] = true;
                return $candidate;
            }
        }
        throw new \LogicException('Cannot name the PDF.');
    }

    /**
     * One CSV row of an issued invoice or credit note: amounts are the stored minor units as decimal
     * strings with a comma (credit notes negative); Bezahlt/Offen/Status follow the same rules as the
     * invoice list (paid = payments, credited = issued credit notes of the invoice).
     *
     * @param array<string, mixed> $d
     * @param list<array<string, mixed>> $payments
     */
    private function csvRow(array $d, array $payments, int $credited, string $today): string
    {
        $gross = (int) $d['gross_minor'];
        if ($d['type'] === DocType::CREDIT_NOTE) {
            $paid = 0;
            $open = 0;
            $status = 'Gutschrift';
        } else {
            $paid = array_sum(array_map(fn (array $p): int => (int) $p['amount_minor'], $payments));
            $open = max(0, $gross - $paid - $credited);
            $status = match (true) {
                $credited > 0 && $credited >= $gross => 'gutgeschrieben',
                $open === 0 => 'bezahlt',
                is_string($d['due_date'] ?? null) && substr((string) $d['due_date'], 0, 10) < $today => 'überfällig',
                $paid > 0 => 'teilbezahlt',
                default => 'offen',
            };
        }
        $name = (string) ($d['buyer']['name'] ?? $d['buyer_name'] ?? '');
        return $this->csvLine(
            [(string) $d['number'], $d['type'] === DocType::CREDIT_NOTE ? 'Gutschrift' : 'Rechnung', substr((string) $d['issue_date'], 0, 10),
                substr((string) ($d['due_date'] ?? ''), 0, 10), $name, (string) $d['currency'],
                self::amount((int) $d['net_minor']), self::amount((int) $d['vat_minor']), self::amount($gross),
                self::amount($paid), self::amount($open), $status],
            [6, 7, 8, 9, 10]
        );
    }

    /** Minor units as a German decimal string, integer arithmetic only: 123456 => "1234,56", -5 => "-0,05". */
    public static function amount(int $minor): string
    {
        $abs = $minor < 0 ? -$minor : $minor;
        return ($minor < 0 ? '-' : '') . intdiv($abs, 100) . ',' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param list<string> $cells
     * @param list<int> $numeric indexes of amount cells (exempt from the formula-injection prefix)
     */
    private function csvLine(array $cells, array $numeric): string
    {
        $out = [];
        foreach ($cells as $i => $cell) {
            // CSV injection: a text cell that a spreadsheet would read as a formula gets a leading quote.
            if (!in_array($i, $numeric, true) && $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false) {
                $cell = "'" . $cell;
            }
            $out[] = strpbrk($cell, ";\"\r\n") !== false ? '"' . str_replace('"', '""', $cell) . '"' : $cell;
        }
        return implode(';', $out) . "\r\n";
    }

    private function readme(string $companyName, string $lang, ?int $year, bool $includePdfs): string
    {
        $t = fn (string $key, array $vars = []): string => $this->translator->t($key, $lang, $vars);
        $date = (new \DateTimeImmutable('@' . ($this->clock)()))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i');
        $lines = [
            $t('export.readme_title'),
            str_repeat('=', 40),
            $t('export.readme_company', ['name' => $companyName]),
            $t('export.readme_date', ['date' => $date]),
            $t('export.readme_scope', ['scope' => $year === null ? $t('export.scope_all') : $t('export.scope_year', ['year' => $year])]),
            $t('export.readme_pdfs', ['pdfs' => $includePdfs ? $t('export.pdfs_included') : $t('export.pdfs_excluded')]),
            '',
            $t('export.readme_files'),
            '  company.json, users.json, customers.json, items.json, documents.json, email_log.json, numbering.json',
            '  invoices.csv, pdf/',
            '',
            $t('export.readme_amounts'),
            $t('export.readme_csv'),
            $t('export.readme_scope_note'),
            $t('export.readme_no_secrets'),
            $t('export.readme_customer_data'),
            '',
        ];
        return implode("\r\n", $lines);
    }

    private function ensureTmpDir(): void
    {
        if (!is_dir($this->tmpDir) && !@mkdir($this->tmpDir, 0700, true) && !is_dir($this->tmpDir)) {
            throw new \RuntimeException('Cannot create the export temp directory.');
        }
    }

    /** Removes export leftovers (an aborted download) that are older than an hour. */
    private function purgeStale(): void
    {
        $limit = time() - self::STALE_SECONDS;
        foreach (glob($this->tmpDir . '/export-*') ?: [] as $path) {
            if (@filemtime($path) !== false && filemtime($path) < $limit) {
                is_dir($path) ? $this->removeTree($path) : @unlink($path);
            }
        }
    }

    private function removeTree(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->removeTree($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}
