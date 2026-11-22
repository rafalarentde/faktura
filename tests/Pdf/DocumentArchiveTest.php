<?php
declare(strict_types=1);

namespace Tests\Pdf;

use App\Domain\DocumentNotFound;
use App\Pdf\ArchiveCorrupt;
use App\Pdf\DocumentArchive;
use App\Pdf\PdfNotAvailable;
use App\Storage\LogoStore;
use PHPUnit\Framework\TestCase;

/** I3: GoBD archive. The PDF served for an issued document is the one archived at its first render. */
final class DocumentArchiveTest extends TestCase
{
    private PdfFixture $f;
    private int $a;
    private int $customer;
    private int $doc;
    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        $this->f = new PdfFixture();
        $this->a = $this->f->companyA;
        $this->customer = $this->f->customer($this->a);
        $this->doc = $this->f->issued($this->a, $this->customer);
    }

    protected function tearDown(): void
    {
        $this->f->cleanup();
        foreach ($this->tmp as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    private function archiveFile(int $company, int $doc): string
    {
        return $this->f->storage . '/' . DocumentArchive::relativePath($company, $doc);
    }

    /** @return array<string, mixed>|null */
    private function row(int $doc): ?array
    {
        return $this->f->db->one('SELECT * FROM document_archives WHERE document_id = ?', [$doc]);
    }

    /** A copy of the lang directory in which one German text differs. */
    private function changedTranslator(): \App\I18n\Translator
    {
        $dir = sys_get_temp_dir() . '/faktura-lang-' . bin2hex(random_bytes(5));
        mkdir($dir);
        $this->tmp[] = $dir;
        foreach (['de', 'pl', 'en'] as $l) {
            $src = (string) file_get_contents(PdfFixture::ROOT . "/lang/$l.php");
            file_put_contents("$dir/$l.php", str_replace("'invoice.service_date' => 'Leistungsdatum'", "'invoice.service_date' => 'Lieferdatum GEAENDERT'", $src));
        }
        return new \App\I18n\Translator($dir);
    }

    public function test_first_render_is_archived_with_checksum_and_private_modes(): void
    {
        $r = $this->f->archivingRenderer($this->a)->renderFile($this->doc);
        $file = $this->archiveFile($this->a, $this->doc);
        $this->assertFileExists($file);
        $this->assertSame($r['content'], file_get_contents($file));
        $this->assertStringStartsWith('%PDF', $r['content']);
        $this->assertSame(hash('sha256', $r['content']), $r['sha256']);
        $row = $this->row($this->doc);
        $this->assertSame($r['sha256'], $row['sha256']);
        $this->assertSame(strlen($r['content']), (int) $row['size_bytes']);
        $this->assertSame($this->a, (int) $row['company_id']);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms(dirname($file))), -4));
        $this->assertSame([], glob(dirname($file) . '/.*.tmp'), 'no temp file is left behind');
    }

    public function test_changed_texts_and_logo_after_issue_do_not_change_the_served_pdf(): void
    {
        $first = $this->f->archivingRenderer($this->a)->renderFile($this->doc)['content'];

        // a lang text changed ...
        $second = $this->f->archivingRenderer($this->a, $this->changedTranslator())->renderFile($this->doc)['content'];
        $this->assertSame($first, $second);
        // ... the logo was uploaded ...
        $png = tempnam(sys_get_temp_dir(), 'logo');
        $im = imagecreatetruecolor(200, 60);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 30, 30));
        imagepng($im, $png);
        $store = new LogoStore($this->f->storage);
        $clean = $store->process($png);
        unlink($png);
        $this->f->db->exec('UPDATE companies SET logo_path = ? WHERE id = ?', [$store->save($this->a, $clean['bytes'], $clean['ext']), $this->a]);
        $third = $this->f->archivingRenderer($this->a)->renderFile($this->doc)['content'];
        $this->assertSame($first, $third);
        $this->assertSame(hash('sha256', $first), $this->row($this->doc)['sha256']);

        // control: without the archive the same changes DO change a render (so the test proves something)
        $live = $this->f->renderer($this->a)->renderFile($this->doc)['content'];
        $this->assertNotSame($first, $live);
        // and a document issued now gets the new look
        $new = $this->f->issued($this->a, $this->customer);
        $this->assertNotSame($first, $this->f->archivingRenderer($this->a, $this->changedTranslator())->renderFile($new)['content']);
    }

    public function test_the_archive_is_write_once(): void
    {
        $r = $this->f->archivingRenderer($this->a)->renderFile($this->doc);
        $kept = $this->f->archive($this->a)->store($this->doc, '%PDF-other bytes');
        $this->assertSame($r['content'], $kept['bytes']);
        $this->assertSame($r['sha256'], $kept['sha256']);
        $this->assertSame($r['content'], file_get_contents($this->archiveFile($this->a, $this->doc)));
        $this->assertSame(1, (int) $this->f->db->one('SELECT COUNT(*) AS n FROM document_archives')['n']);
        $this->expectException(\PDOException::class); // the unique index itself
        $this->f->db->exec('INSERT INTO document_archives (company_id, document_id, sha256, size_bytes) VALUES (?, ?, ?, 1)', [$this->a, $this->doc, 'x']);
    }

    public function test_a_stale_file_without_a_row_is_replaced_not_served(): void
    {
        $file = $this->archiveFile($this->a, $this->doc);
        mkdir(dirname($file), 0700, true);
        file_put_contents($file, 'stale bytes of a lost database');
        $r = $this->f->archivingRenderer($this->a)->renderFile($this->doc);
        $this->assertStringStartsWith('%PDF', $r['content']);
        $this->assertSame($r['content'], file_get_contents($file));
    }

    public function test_a_missing_file_is_restored_and_audited(): void
    {
        $renderer = $this->f->archivingRenderer($this->a);
        $first = $renderer->renderFile($this->doc);
        unlink($this->archiveFile($this->a, $this->doc));
        $again = $renderer->renderFile($this->doc);
        $this->assertStringStartsWith('%PDF', $again['content']);
        $this->assertFileExists($this->archiveFile($this->a, $this->doc));
        $this->assertSame($again['content'], file_get_contents($this->archiveFile($this->a, $this->doc)));
        $this->assertSame($again['sha256'], $this->row($this->doc)['sha256']);
        $audit = $this->f->db->all("SELECT * FROM audit_log WHERE action = 'document.archive_restored'");
        $this->assertCount(1, $audit);
        $detail = json_decode($audit[0]['detail'], true);
        $this->assertSame($this->doc, $detail['document_id']);
        $this->assertSame($first['sha256'] !== $again['sha256'], $detail['sha_changed']);
        $this->assertSame($this->a, (int) $audit[0]['company_id']);
        // and from now on the restored copy is served without another event
        $renderer->renderFile($this->doc);
        $this->assertCount(1, $this->f->db->all("SELECT id FROM audit_log WHERE action = 'document.archive_restored'"));
    }

    public function test_a_restore_after_a_text_change_records_the_new_checksum(): void
    {
        $first = $this->f->archivingRenderer($this->a)->renderFile($this->doc);
        unlink($this->archiveFile($this->a, $this->doc));
        $again = $this->f->archivingRenderer($this->a, $this->changedTranslator())->renderFile($this->doc);
        $this->assertNotSame($first['sha256'], $again['sha256']);
        $this->assertSame($again['sha256'], $this->row($this->doc)['sha256']);
        $detail = json_decode($this->f->db->one("SELECT detail FROM audit_log WHERE action = 'document.archive_restored'")['detail'], true);
        $this->assertTrue($detail['sha_changed']);
    }

    public function test_a_tampered_file_is_refused_and_never_overwritten(): void
    {
        $renderer = $this->f->archivingRenderer($this->a);
        $renderer->renderFile($this->doc);
        $file = $this->archiveFile($this->a, $this->doc);
        file_put_contents($file, 'tampered');
        try {
            $renderer->renderFile($this->doc);
            $this->fail('ArchiveCorrupt expected');
        } catch (ArchiveCorrupt) {
        }
        $this->assertSame('tampered', file_get_contents($file));
    }

    public function test_drafts_are_never_archived(): void
    {
        $draft = $this->f->draft($this->a, $this->customer);
        try {
            $this->f->archivingRenderer($this->a)->renderFile($draft);
            $this->fail('PdfNotAvailable expected');
        } catch (PdfNotAvailable) {
        }
        $this->assertNull($this->row($draft));
        $this->assertFileDoesNotExist($this->archiveFile($this->a, $draft));
        $this->assertSame([], glob($this->f->storage . '/archive/*/*') ?: []);
    }

    public function test_tenants_are_isolated(): void
    {
        $this->f->archivingRenderer($this->a)->renderFile($this->doc);
        $file = $this->archiveFile($this->a, $this->doc);
        $this->assertStringContainsString('/archive/' . $this->a . '/', $file);

        // another company cannot render, find or restore it
        try {
            $this->f->archivingRenderer($this->f->companyB)->renderFile($this->doc);
            $this->fail('DocumentNotFound expected');
        } catch (DocumentNotFound) {
        }
        $other = $this->f->archive($this->f->companyB);
        $this->assertSame(['state' => 'none'], $other->fetch($this->doc));
        foreach (['store' => fn () => $other->store($this->doc, '%PDF-x'), 'restore' => fn () => $other->restore($this->doc, '%PDF-x')] as $name => $call) {
            try {
                $call();
                $this->fail("$name must refuse a foreign document");
            } catch (DocumentNotFound) {
            }
        }
        $this->assertNotSame($other->path($this->doc), $file);
        $this->assertStringContainsString('/archive/' . $this->f->companyB . '/', $other->path($this->doc));
        $this->assertSame(1, (int) $this->f->db->one('SELECT COUNT(*) AS n FROM document_archives')['n']);

        // company B's own document lands in B's directory
        $cb = $this->f->customer($this->f->companyB);
        $docB = $this->f->issued($this->f->companyB, $cb);
        $this->f->archivingRenderer($this->f->companyB)->renderFile($docB);
        $this->assertFileExists($this->archiveFile($this->f->companyB, $docB));
        $this->assertSame($this->f->companyB, (int) $this->row($docB)['company_id']);
    }

    public function test_the_archive_lives_outside_the_purged_directories_and_the_web_root(): void
    {
        $this->f->archivingRenderer($this->a)->renderFile($this->doc);
        $file = $this->archiveFile($this->a, $this->doc);
        $this->assertStringNotContainsString('/pdf-cache/', $file);
        $this->assertStringNotContainsString('/tmp/', substr($file, strlen($this->f->storage)));
        $this->assertStringNotContainsString('/public/', $file);
        $this->assertFileExists($file);
    }
}
