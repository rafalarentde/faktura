<?php
declare(strict_types=1);

namespace App\Pdf;

use App\Core\Audit;
use App\Core\Db;
use App\Domain\DocumentNotFound;
use App\Repo\BaseRepo;
use App\Tenant\CompanyContext;

/**
 * GoBD archive of the PDFs of ISSUED documents (invoices, credit notes, offers, dunning letters).
 *
 * The bytes of the first render are stored under <storage>/archive/<company_id>/<document_id>.pdf
 * (directories 0700, files 0600, outside the web root) and every later download, e-mail attachment
 * and export serves exactly these bytes, so a later change of a text, the logo or the layout never
 * changes a document that was already issued. The table document_archives records the SHA-256 of the
 * bytes; its UNIQUE index on document_id makes the archive write-once.
 *
 * - The path is built from the context company id and the integer document id only; a document of
 *   another company is never found (every table access is company-scoped, BaseRepo).
 * - store(): the row is inserted FIRST (inside a transaction together with the file write), so of
 *   several concurrent first renders exactly one wins and writes the file; the others serve what the
 *   winner stored. A file that already sits at the path without a row is an orphan (e.g. left by a crash
 *   or a database restored from an older backup): it is replaced by the winner, never trusted.
 * - fetch() verifies the checksum on every read: a file that does not match its recorded SHA-256 is
 *   an integrity failure (ArchiveCorrupt), it is neither served nor overwritten.
 * - restore(): the row exists but the file is gone (lost storage, restore from a backup without the
 *   archive directory): the caller's fresh render is written back WITHOUT overwriting anything, the
 *   recorded checksum is updated if the new render differs and the event is audited as
 *   document.archive_restored (a re-render can differ from the lost original if texts or the logo changed).
 */
final class DocumentArchive extends BaseRepo
{
    private const TABLE = 'document_archives';
    public const DIR_MODE = 0700;
    public const FILE_MODE = 0600;

    private string $root;
    private ?Audit $audit = null;

    public function __construct(Db $db, CompanyContext $ctx, string $storagePath, private \Closure $clock)
    {
        parent::__construct($db, $ctx);
        $this->root = rtrim($storagePath, '/') . '/archive';
    }

    /** Relative path of a document's archived PDF below the storage directory. */
    public static function relativePath(int $companyId, int $documentId): string
    {
        return 'archive/' . $companyId . '/' . $documentId . '.pdf';
    }

    /** Absolute path of the archived PDF of a document of the context company (it may not exist). */
    public function path(int $documentId): string
    {
        return $this->root . '/' . $this->ctx->id() . '/' . $documentId . '.pdf';
    }

    /**
     * @return array{state: 'none'|'missing'|'ok', bytes?: string, sha256?: string}
     *   none = never archived, missing = recorded but the file is gone, ok = verified bytes
     * @throws ArchiveCorrupt the file does not match the recorded checksum
     */
    public function fetch(int $documentId): array
    {
        $row = $this->row($documentId);
        if ($row === null) {
            return ['state' => 'none'];
        }
        $file = $this->path($documentId);
        $bytes = is_file($file) ? @file_get_contents($file) : false;
        if ($bytes === false) {
            return ['state' => 'missing', 'sha256' => (string) $row['sha256']];
        }
        if (!hash_equals((string) $row['sha256'], hash('sha256', $bytes))) {
            throw new ArchiveCorrupt($documentId);
        }
        return ['state' => 'ok', 'bytes' => $bytes, 'sha256' => (string) $row['sha256']];
    }

    /**
     * Archives the first render of a document. When another request archived it first, that copy is
     * returned instead (write-once: the bytes passed here are then discarded).
     *
     * @return array{bytes: string, sha256: string} what every caller must serve
     * @throws DocumentNotFound the document is not one of the context company
     * @throws \RuntimeException the file cannot be written (the row is rolled back)
     * @throws ArchiveCorrupt
     */
    public function store(int $documentId, string $bytes): array
    {
        $this->assertDocument($documentId);
        $sha = hash('sha256', $bytes);
        try {
            $this->db->transaction(function () use ($documentId, $bytes, $sha): void {
                $this->scopedInsert(self::TABLE, [
                    'document_id' => $documentId, 'sha256' => $sha, 'size_bytes' => strlen($bytes),
                    'created_at' => gmdate('Y-m-d H:i:s', ($this->clock)()),
                ]);
                $this->write($this->path($documentId), $bytes, true);
            });
        } catch (\PDOException $e) {
            if (!str_starts_with((string) ($e->errorInfo[0] ?? $e->getCode()), '23')) {
                throw $e;
            }
            // Lost the race: serve the winner's copy (restoring the file if the winner has not written it yet).
            $theirs = $this->fetch($documentId);
            if ($theirs['state'] === 'ok') {
                return ['bytes' => $theirs['bytes'], 'sha256' => $theirs['sha256']];
            }
            return $this->restore($documentId, $bytes);
        }
        return ['bytes' => $bytes, 'sha256' => $sha];
    }

    /**
     * Writes a fresh render back after the archived file was lost. Never overwrites a file; the recorded
     * checksum follows the new bytes when they differ. Audited as document.archive_restored.
     *
     * @return array{bytes: string, sha256: string}
     * @throws \RuntimeException the file cannot be written
     */
    public function restore(int $documentId, string $bytes): array
    {
        $row = $this->row($documentId) ?? throw new DocumentNotFound();
        $sha = hash('sha256', $bytes);
        $file = $this->path($documentId);
        if (is_file($file)) {
            // Somebody else restored it meanwhile: serve that copy.
            $theirs = $this->fetch($documentId);
            if ($theirs['state'] === 'ok') {
                return ['bytes' => $theirs['bytes'], 'sha256' => $theirs['sha256']];
            }
        }
        $changed = !hash_equals((string) $row['sha256'], $sha);
        $this->db->transaction(function () use ($documentId, $row, $bytes, $sha, $changed, $file): void {
            if ($changed) {
                $this->scopedUpdate(self::TABLE, (int) $row['id'], ['sha256' => $sha, 'size_bytes' => strlen($bytes)]);
            }
            $this->write($file, $bytes, false);
            $this->audit ??= new Audit($this->db, $this->clock);
            $this->audit->log('document.archive_restored', ['document_id' => $documentId, 'sha_changed' => $changed], $this->ctx->id(), $this->ctx->userId());
        });
        return ['bytes' => $bytes, 'sha256' => $sha];
    }

    /** @return array<string, mixed>|null */
    private function row(int $documentId): ?array
    {
        return $this->scopedAll(self::TABLE, 'document_id = ?', [$documentId], '', 1)[0] ?? null;
    }

    private function assertDocument(int $documentId): void
    {
        if ($this->scopedOne('documents', $documentId) === null) {
            throw new DocumentNotFound();
        }
    }

    /**
     * Atomic file write: temp file in the same directory (0600), then rename (replaces an orphan) or, with
     * $replace false, link() which refuses to overwrite an existing file.
     */
    private function write(string $file, string $bytes, bool $replace): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, self::DIR_MODE, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the archive directory (is storage/archive writable?).');
        }
        $tmp = $dir . '/.' . bin2hex(random_bytes(8)) . '.tmp';
        $old = umask(0177);
        try {
            $ok = @file_put_contents($tmp, $bytes, LOCK_EX) === strlen($bytes);
        } finally {
            umask($old);
        }
        if (!$ok) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write the archive file (is storage/archive writable?).');
        }
        @chmod($tmp, self::FILE_MODE);
        if ($replace) {
            $done = @rename($tmp, $file);
        } else {
            $done = @link($tmp, $file) || is_file($file);
            @unlink($tmp);
        }
        if (!$done) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot place the archive file.');
        }
    }
}
