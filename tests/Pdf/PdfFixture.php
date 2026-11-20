<?php
declare(strict_types=1);

namespace Tests\Pdf;

use App\Core\Db;
use App\Domain\DocumentService;
use App\Domain\NumberSequences;
use App\Http\DocumentPresenter;
use App\I18n\Translator;
use App\Pdf\PdfRenderer;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Storage\LogoStore;
use App\Tenant\CompanyContext;
use App\Vies\ViesStatus;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Shared fixture for the PDF and presenter tests: an in-memory database with two companies, a
 * user who belongs to both, the real DocumentService (documents are issued exactly as in the app)
 * and helpers to read rendered PDFs with poppler (pdftotext/pdfinfo/pdffonts).
 */
final class PdfFixture
{
    public const ROOT = __DIR__ . '/../..';
    public const NOW = 1_795_514_400; // 2026-11-24 10:00:00 UTC

    public Db $db;
    public int $userId;
    public int $companyA;
    public int $companyB;
    public string $storage;

    public function __construct()
    {
        $this->db = \TestDb::fresh();
        $this->storage = sys_get_temp_dir() . '/faktura-pdf-' . bin2hex(random_bytes(6));
        mkdir($this->storage . '/logos', 0775, true);
        $this->db->exec('INSERT INTO users (email, password_hash) VALUES (?, ?)', ['o@example.com', 'x']);
        $this->userId = $this->db->lastId();
        $this->companyA = $this->company([]);
        $this->companyB = $this->company(['name' => 'Fremd AG']);
    }

    /** Removes the temporary storage directory (logos, nothing else). */
    public function cleanup(): void
    {
        foreach (glob($this->storage . '/logos/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storage . '/logos');
        @rmdir($this->storage);
    }

    /** @param array<string, mixed> $over */
    public function company(array $over): int
    {
        $row = $over + [
            'name' => 'Muster Service GmbH', 'address' => "Musterweg 1\n10115 Berlin", 'tax_number' => '27/123/45678',
            'vat_id' => 'DE987654321', 'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'bank' => 'Commerzbank Berlin',
            'intro_text' => 'Vielen Dank für deinen Auftrag.', 'outro_text' => 'Mit freundlichen Grüßen', 'default_payment_days' => 14,
        ];
        $this->db->exec(
            'INSERT INTO companies (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')',
            array_values($row)
        );
        $id = $this->db->lastId();
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$id, $this->userId, 'owner']);
        return $id;
    }

    public function ctx(int $companyId): CompanyContext
    {
        return CompanyContext::forUser($this->db, $this->userId, $companyId);
    }

    public function service(int $companyId): DocumentService
    {
        $ctx = $this->ctx($companyId);
        $clock = fn (): int => self::NOW;
        return new DocumentService($this->db, $ctx, new NumberSequences($this->db, $ctx, $clock), new DocumentRepo($this->db, $ctx),
            new CustomerRepo($this->db, $ctx), $clock);
    }

    public function repo(int $companyId): DocumentRepo
    {
        return new DocumentRepo($this->db, $this->ctx($companyId));
    }

    public function translator(): Translator
    {
        return new Translator(self::ROOT . '/lang');
    }

    public function presenter(int $companyId): DocumentPresenter
    {
        return new DocumentPresenter($this->translator(), $this->repo($companyId));
    }

    public function renderer(int $companyId): PdfRenderer
    {
        $twig = new Environment(new FilesystemLoader(self::ROOT . '/templates'), ['autoescape' => 'html', 'strict_variables' => true]);
        return new PdfRenderer($twig, $this->presenter($companyId), $this->repo($companyId), new LogoStore($this->storage),
            $this->ctx($companyId), self::ROOT . '/resources', self::cacheDir());
    }

    /** Font cache shared by all PDF tests (registering the fonts is the slow part). */
    public static function cacheDir(): string
    {
        return sys_get_temp_dir() . '/faktura-pdf-test-cache';
    }

    /** @param array<string, mixed> $data */
    public function customer(int $companyId, array $data = [], ?ViesStatus $vies = null): int
    {
        $repo = new CustomerRepo($this->db, $this->ctx($companyId));
        $id = $repo->save($data + ['name' => 'Bauhaus Logistik GmbH', 'address' => "Hauptstraße 5\n80331 München", 'country' => 'DE',
            'vat_id' => 'DE123456789', 'is_company' => '1', 'payment_days' => '14', 'default_lang' => 'de', 'default_currency' => 'EUR']);
        if ($vies !== null) {
            $repo->recordVies($id, $vies, new \DateTimeImmutable('@' . self::NOW));
        }
        return $id;
    }

    /** @return list<array<string, mixed>> the mockup lines: 2 x 450,00 @19, 1 x 60,00 @19, 5 x 12,00 @7 */
    public static function makietaLines(): array
    {
        return [
            self::line(['name' => 'Beratung / Workshop', 'qty_milli' => 2000, 'unit_minor' => 45000]),
            self::line(['name' => 'Anfahrtspauschale', 'unit' => 'flat', 'unit_minor' => 6000]),
            self::line(['name' => 'Schulungsunterlagen', 'unit' => 'piece', 'qty_milli' => 5000, 'unit_minor' => 1200, 'vat_bp' => 700]),
        ];
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    public static function line(array $o = []): array
    {
        return $o + ['name' => 'Beratung', 'description' => '', 'unit' => 'hour', 'qty_milli' => 1000, 'unit_minor' => 10000,
            'discount_bp' => 0, 'vat_bp' => 1900];
    }

    /** @param array<string, mixed> $data */
    public function draft(int $companyId, int $customer, array $data = []): int
    {
        return $this->service($companyId)->createDraft('invoice', $data + [
            'customer_id' => $customer, 'issue_date' => '2026-11-18', 'service_date' => '2026-11-17', 'due_date' => '2026-12-02',
            'lines' => self::makietaLines(),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function issued(int $companyId, int $customer, array $data = []): int
    {
        $id = $this->draft($companyId, $customer, $data);
        $this->service($companyId)->issue($id, new \DateTimeImmutable('2026-11-18 10:00:00', new \DateTimeZone('Europe/Berlin')), true);
        return $id;
    }

    /** Issued credit note for $invoiceId with the given lines (all lines of the invoice when null). */
    public function creditNote(int $companyId, int $invoiceId, ?array $lines = null): int
    {
        $svc = $this->service($companyId);
        $id = $svc->createCorrection($invoiceId);
        if ($lines !== null) {
            $svc->updateDraft($id, ['lines' => $lines]);
        }
        $svc->issue($id, new \DateTimeImmutable('2026-11-20 10:00:00', new \DateTimeZone('Europe/Berlin')));
        return $id;
    }

    // ---- reading PDFs ----

    public static function hasPoppler(): bool
    {
        return trim((string) shell_exec('command -v pdftotext')) !== '' && trim((string) shell_exec('command -v pdfinfo')) !== '';
    }

    /** Text of the PDF (pdftotext, reading order); NBSP and narrow NBSP become plain spaces. */
    public static function text(string $pdf, bool $layout = false): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', self::poppler('pdftotext' . ($layout ? ' -layout' : '') . ' -enc UTF-8 %s -', $pdf));
    }

    /** The text with every run of whitespace folded to one space (for sentences that wrap). */
    public static function flat(string $pdf): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', self::text($pdf)));
    }

    /** @return array<string, string> pdfinfo fields */
    public static function info(string $pdf): array
    {
        $out = [];
        foreach (explode("\n", self::poppler('pdfinfo -enc UTF-8 %s', $pdf)) as $line) {
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $m) === 1) {
                $out[$m[1]] = $m[2];
            }
        }
        return $out;
    }

    public static function fonts(string $pdf): string
    {
        return self::poppler('pdffonts %s', $pdf);
    }

    public static function images(string $pdf): string
    {
        return self::poppler('pdfimages -list %s', $pdf);
    }

    private static function poppler(string $command, string $pdf): string
    {
        $file = tempnam(sys_get_temp_dir(), 'faktura-pdf-');
        file_put_contents($file, $pdf);
        try {
            return (string) shell_exec(sprintf($command, escapeshellarg($file)) . ' 2>/dev/null');
        } finally {
            unlink($file);
        }
    }
}
