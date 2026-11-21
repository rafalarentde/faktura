<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\Response;
use Tests\Pdf\PdfFixture;
use Tests\Support\ExportFixture;

/** GET/POST /settings/export (Task 23): access, headers, isolation, secrets, rate limit, temp files, audit. */
final class ExportTest extends HttpTestCase
{
    private const EMAIL = 'owner-alpha@example.com';

    private static string $storage = '';
    private ExportFixture $fx;
    private int $a;
    private int $b;
    private int $owner;
    private string $secret;
    /** @var array<string, int|string> */
    private array $ids = [];
    /** @var list<string> */
    private array $codes = [];

    public static function setUpBeforeClass(): void
    {
        // Same stable directory as the other PDF route tests (Dompdf keeps static font state keyed by the cache path).
        self::$storage = sys_get_temp_dir() . '/faktura-pdf-route-storage';
        if (!is_dir(self::$storage . '/logos')) {
            mkdir(self::$storage . '/logos', 0775, true);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanTmp();
        $this->now = (new \DateTimeImmutable('2026-11-18 10:00:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp();
        $this->app = $this->makeApp(['storage_path' => self::$storage]);
        $this->fx = new ExportFixture($this->db, $this->now);
        $u = $this->createUser(self::EMAIL);
        $this->owner = $u['id'];
        $this->secret = (string) $u['secret'];
        $ub = $this->fx->user('owner-zeta@example.com');
        $this->codes = $ub['backup_codes'];
        $this->a = $this->fx->company('Alpha Service GmbH', 'active', 'ALPHA');
        $this->b = $this->fx->company('Zeta Fremd AG', 'active', 'ZETA');
        $this->fx->member($this->owner, $this->a);
        $this->fx->member($ub['id'], $this->b);
        $this->ids = $this->fx->populate($this->a, $this->owner, 'ALPHA');
        $this->fx->populate($this->b, $ub['id'], 'ZETA');
    }

    protected function tearDown(): void
    {
        $this->cleanTmp();
    }

    private function tmpDir(): string
    {
        return self::$storage . '/tmp';
    }

    /** @return list<string> */
    private function tmpFiles(): array
    {
        return glob($this->tmpDir() . '/*') ?: [];
    }

    private function cleanTmp(): void
    {
        foreach ($this->tmpFiles() as $f) {
            if (is_dir($f)) {
                foreach (glob($f . '/*') ?: [] as $g) {
                    @unlink($g);
                }
                @rmdir($f);
            } else {
                @unlink($f);
            }
        }
    }

    private function login(): void
    {
        $this->loginFully($this->secret, self::EMAIL);
    }

    /** @param array<string, string> $data */
    private function export(array $data = []): Response
    {
        return $this->post('/settings/export', $data);
    }

    /** @return array<string, string> entry => bytes (reads the temp file and removes it, as send() would) */
    private function zip(Response $r): array
    {
        $this->assertSame(200, $r->status, substr($r->body, 0, 300));
        $this->assertNotNull($r->file);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open((string) $r->file) === true);
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        $r->discard();
        return $out;
    }

    private function audits(string $action = 'export.create'): array
    {
        return $this->db->all('SELECT * FROM audit_log WHERE action = ? ORDER BY id', [$action]);
    }

    // ---- access ----

    public function test_unauthenticated_requests_go_to_login(): void
    {
        $this->assertRedirect('/login', $this->get('/settings/export'));
        $this->assertRedirect('/login', $this->export());
        $this->assertSame([], $this->tmpFiles());
    }

    public function test_user_without_company_is_forbidden(): void
    {
        $solo = $this->createUser('solo@example.pl');
        $this->loginFully((string) $solo['secret'], 'solo@example.pl');
        $this->assertSame(403, $this->get('/settings/export')->status);
        $this->assertSame(403, $this->export()->status);
        $this->assertSame([], $this->tmpFiles());
    }

    public function test_role_user_is_forbidden_and_nothing_is_built(): void
    {
        $member = $this->createUser('mitarbeiter@example.pl');
        $this->fx->member($member['id'], $this->a, 'user');
        $this->loginFully((string) $member['secret'], 'mitarbeiter@example.pl');
        $this->assertSame(403, $this->get('/settings/export')->status);
        $r = $this->export(['include_pdfs' => '1']);
        $this->assertSame(403, $r->status);
        $this->assertNull($r->file);
        $this->assertSame([], $this->tmpFiles());
        $this->assertSame([], $this->audits());
        $tabs = $this->get('/settings/security');
        $this->assertSame(200, $tabs->status);
        $this->assertStringNotContainsString('/settings/export', $tabs->body, 'the tab is not offered to the user role');
    }

    public function test_post_without_csrf_is_419(): void
    {
        $this->login();
        $this->assertSame(419, $this->post('/settings/export', ['include_pdfs' => '1'], csrf: false)->status);
        $this->assertSame([], $this->tmpFiles());
        $this->assertSame([], $this->audits());
    }

    public function test_the_company_comes_from_the_session_never_from_the_request(): void
    {
        $this->login();
        $zip = $this->zip($this->export(['company_id' => (string) $this->b, 'company' => (string) $this->b]));
        $this->assertStringContainsString('Alpha Service GmbH', $zip['company.json']);
        $this->assertStringNotContainsString('ZETA', implode('', $zip));
    }

    // ---- the form ----

    public function test_form_page(): void
    {
        $this->login();
        $r = $this->get('/settings/export');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('<form class="form-grid" method="post" action="/settings/export">', $r->body);
        $this->assertStringContainsString('name="_csrf"', $r->body);
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="include_pdfs" value="1" checked>/', $r->body);
        $this->assertStringContainsString('<option value="2026">', $r->body);
        $this->assertStringContainsString('<option value="2025">', $r->body);
        $this->assertStringNotContainsString('<option value="2024">', $r->body);
        $this->assertStringContainsString('Kundendaten', $r->body);
        $this->assertStringContainsString('Bisher kein Export.', $r->body);
        $this->assertStringContainsString('href="/settings/export" aria-current="page"', $r->body);
        $this->assertStringNotContainsString('ZETA', $r->body);
    }

    public function test_form_shows_the_date_of_the_last_export(): void
    {
        $this->login();
        $this->zip($this->export());
        $r = $this->get('/settings/export');
        $this->assertStringContainsString('Letzter Export: 18.11.2026', $r->body);
        $this->assertStringNotContainsString('10:00', $r->body, 'date only');
    }

    public function test_page_renders_in_all_three_languages(): void
    {
        $this->login();
        foreach (['de' => 'Daten exportieren', 'pl' => 'Eksport danych', 'en' => 'Export data'] as $lang => $heading) {
            $this->post('/lang', ['lang' => $lang]);
            $r = $this->get('/settings/export');
            $this->assertSame(200, $r->status, $lang);
            $this->assertStringContainsString($heading, $r->body, $lang);
            $this->assertDoesNotMatchRegularExpression('/\b(export|settings|audit)\.[a-z_]+\b(?![^<]*>)/', strip_tags($r->body), "$lang: no raw translation key");
            $zip = $this->zip($this->export());
            $this->assertStringContainsString($heading === 'Daten exportieren' ? 'Datenexport' : ($lang === 'pl' ? 'Eksport danych' : 'Data export'), $zip['README.txt'], "$lang README");
        }
    }

    // ---- download ----

    public function test_export_contains_only_own_company_data_with_real_pdfs(): void
    {
        $this->login();
        $r = $this->export(['include_pdfs' => '1']);
        $this->assertSame(200, $r->status, substr($r->body, 0, 300));
        $this->assertSame('application/zip', $r->headers['Content-Type']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $this->assertSame((string) filesize((string) $r->file), $r->headers['Content-Length']);
        $this->assertSame('attachment; filename="export-alpha-service-gmbh-2026-11-18.zip"', $r->headers['Content-Disposition']);
        $this->assertSame('', $r->body, 'the body is streamed from the file, not held in memory');
        $this->assertSame(0600, fileperms((string) $r->file) & 0777);
        $zip = $this->zip($r);

        $pdfs = array_filter(array_keys($zip), fn (string $n): bool => str_starts_with($n, 'pdf/'));
        $this->assertCount(5, $pdfs);
        $this->assertArrayNotHasKey('pdf/', $zip);
        foreach ($pdfs as $n) {
            $this->assertStringStartsWith('%PDF-', $zip[$n], $n);
        }
        $foreign = ['ZETA', 'Zeta', 'zeta', 'Fremd AG', 'owner-zeta'];
        foreach ($zip as $name => $bytes) {
            foreach ($foreign as $needle) {
                $this->assertStringNotContainsString($needle, $name);
                if (!str_starts_with($name, 'pdf/')) {
                    $this->assertStringNotContainsString($needle, $bytes, "$name leaks '$needle'");
                }
            }
        }
        if (PdfFixture::hasPoppler()) {
            foreach ($pdfs as $n) {
                $text = PdfFixture::text($zip[$n]);
                $this->assertStringContainsString('ALPHA', $text, $n);
                foreach ($foreign as $needle) {
                    $this->assertStringNotContainsString($needle, $text, "$n leaks '$needle'");
                }
            }
        }
        // Secrets: nothing of the sort in any file, PDFs included.
        $needles = ['smtp_pass_enc', 'password_hash', 'totp_secret', '$argon2', ExportFixture::SMTP_PASSWORD, ExportFixture::USER_PASSWORD, 'TOTPSECRETPLAINTEXT', ...$this->codes];
        foreach ($this->db->all('SELECT smtp_pass_enc FROM companies') as $c) {
            $needles[] = (string) $c['smtp_pass_enc'];
        }
        foreach ($this->db->all('SELECT password_hash, totp_secret_enc FROM users') as $u) {
            $needles[] = (string) $u['password_hash'];
            $needles[] = (string) $u['totp_secret_enc'];
        }
        foreach ($zip as $name => $bytes) {
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $bytes, "$name contains a secret");
            }
        }
        foreach (json_decode($zip['users.json'], true) as $u) {
            $this->assertSame(['email', 'id', 'role', 'ui_lang'], (function (array $k): array { sort($k); return $k; })(array_keys($u)));
        }
    }

    public function test_the_response_is_streamed_and_the_temp_file_removed_by_send(): void
    {
        $this->login();
        $r = $this->export();
        $path = (string) $r->file;
        $this->assertFileExists($path);
        $bytes = (string) file_get_contents($path);
        ob_start();
        $r->send();
        $sent = (string) ob_get_clean();
        $this->assertSame($bytes, $sent);
        $this->assertFileDoesNotExist($path);
        $this->assertSame([], $this->tmpFiles());
        $this->assertNull($r->file);
        $r->discard(); // harmless a second time
    }

    public function test_suspended_company_can_export(): void
    {
        $this->db->exec("UPDATE companies SET access_status = 'suspended' WHERE id = ?", [$this->a]);
        $this->login();
        $this->assertFalse($this->fx->ctx($this->a, $this->owner)->canIssue(new \DateTimeImmutable('@' . $this->now)));
        $this->assertSame(200, $this->get("/settings/export")->status);
        $zip = $this->zip($this->export(['include_pdfs' => '1']));
        $this->assertCount(5, array_filter(array_keys($zip), fn (string $n): bool => str_starts_with($n, 'pdf/')));
        $this->assertCount(6, json_decode($zip['documents.json'], true));
        $this->assertCount(1, $this->audits());
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function companyNames(): iterable
    {
        yield 'umlauts quotes slashes' => ["Müller \"Söhne\" / \\ GmbH", 'export-mueller-soehne-gmbh-2026-11-18.zip'];
        yield 'polish' => ['Łódź Żółć Sp. z o.o.', 'export-lodz-zolc-sp-z-o-o-2026-11-18.zip'];
        yield 'crlf and header injection' => ["Evil\r\nSet-Cookie: x=1\r\n\r\n<script>", 'export-evil-set-cookie-x-1-script-2026-11-18.zip'];
        yield 'nothing usable' => ['"""', 'export-firma-2026-11-18.zip'];
        yield 'long' => [str_repeat('Langer Name ', 20), null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('companyNames')]
    public function test_download_file_name_is_sanitised(string $name, ?string $expected): void
    {
        $this->db->exec('UPDATE companies SET name = ? WHERE id = ?', [$name, $this->a]);
        $this->login();
        $r = $this->export();
        $disposition = $r->headers['Content-Disposition'];
        $this->assertMatchesRegularExpression('/\Aattachment; filename="export-[a-z0-9-]{1,40}-2026-11-18\.zip"\z/', $disposition);
        $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $disposition);
        if ($expected !== null) {
            $this->assertSame('attachment; filename="' . $expected . '"', $disposition);
        }
        $this->zip($r);
    }

    // ---- limits ----

    public function test_at_most_three_exports_per_hour_and_company(): void
    {
        $this->login();
        for ($i = 1; $i <= 3; $i++) {
            $this->zip($this->export());
        }
        $r = $this->export();
        $this->assertSame(429, $r->status);
        $this->assertNull($r->file);
        $this->assertStringContainsString('3 Exporte', $r->body);
        $this->assertCount(3, $this->audits(), 'a refused export is not logged as created');
        $this->assertSame([], $this->tmpFiles());
        $this->now += 3601;
        $this->zip($this->export());
        $this->assertCount(4, $this->audits());
    }

    public function test_the_limit_is_per_company(): void
    {
        $second = $this->fx->company('Beta GmbH', 'active', 'BETA');
        $this->fx->member($this->owner, $second);
        $this->login();
        $this->assertRedirect('/', $this->post('/company/switch', ['company_id' => (string) $this->a]));
        for ($i = 1; $i <= 3; $i++) {
            $this->zip($this->export());
        }
        $this->assertSame(429, $this->export()->status);
        $this->assertRedirect('/', $this->post('/company/switch', ['company_id' => (string) $second]));
        $zip = $this->zip($this->export());
        $this->assertStringContainsString('Beta GmbH', $zip['company.json']);
    }

    public function test_invalid_options_are_refused_without_spending_the_budget(): void
    {
        $this->login();
        foreach (['abcd', '20', '2026-01', '99999'] as $year) {
            $r = $this->export(['year' => $year]);
            $this->assertSame(422, $r->status, $year);
            $this->assertStringContainsString('gültigen Zeitraum', $r->body);
        }
        $this->assertSame(0, $this->exportHits(), 'no rate-limit hit');
        $this->assertSame([], $this->audits());
        $this->assertSame([], $this->tmpFiles());
        // too many PDFs: the user is told what to do, still no budget used
        $this->app = $this->makeApp(['storage_path' => self::$storage, 'export_max_pdfs' => 2]);
        // (a new App instance shares the session store, like the browser cookie: still logged in)
        $r = $this->export(['include_pdfs' => '1']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('einzelnes Jahr', $r->body);
        $this->assertSame(0, $this->exportHits(), 'no budget spent by a refused request');
        $zip = $this->zip($this->export(['include_pdfs' => '1', 'year' => '2025']));
        $this->assertCount(1, array_filter(array_keys($zip), fn (string $n): bool => str_starts_with($n, 'pdf/')));
        $this->assertSame(1, $this->exportHits());
        $zip = $this->zip($this->export());
        $this->assertSame([], array_filter(array_keys($zip), fn (string $n): bool => str_starts_with($n, 'pdf/')), 'unticked PDFs: no limit');
    }

    private function exportHits(): int
    {
        return (int) $this->db->one('SELECT COUNT(*) AS c FROM login_attempts WHERE `key` = ?', [hash('sha256', 'export:company:' . $this->a)])['c'];
    }

    // ---- audit ----

    public function test_export_is_audited_with_ids_and_counts_only(): void
    {
        $this->login();
        $this->zip($this->export());
        $this->zip($this->export(['include_pdfs' => '1', 'year' => '2025']));
        $rows = $this->audits();
        $this->assertCount(2, $rows);
        $this->assertSame(['include_pdfs' => false, 'year' => null, 'doc_count' => 6, 'pdf_count' => 0], json_decode($rows[0]['detail'], true));
        $this->assertSame(['include_pdfs' => true, 'year' => 2025, 'doc_count' => 1, 'pdf_count' => 1], json_decode($rows[1]['detail'], true));
        foreach ($rows as $row) {
            $this->assertSame($this->a, (int) $row['company_id']);
            $this->assertSame($this->owner, (int) $row['user_id']);
        }
    }

    // ---- failures never leave a file behind ----

    public function test_renderer_failure_is_a_500_and_leaves_no_temp_file(): void
    {
        $this->db->exec('UPDATE documents SET seller_json = ? WHERE id = ?', ['{broken json', $this->ids['offer']]);
        $this->login();
        $r = $this->export(['include_pdfs' => '1']);
        $this->assertSame(500, $r->status);
        $this->assertNull($r->file);
        $this->assertSame([], $this->tmpFiles());
        $this->assertSame([], $this->audits(), 'no audit row for an export that was not delivered');
        $this->assertStringNotContainsString('broken', $r->body, 'no detail on the error page');
    }

    public function test_a_failing_audit_write_removes_the_temp_file_and_delivers_nothing(): void
    {
        $this->login();
        $this->db->exec('DROP TABLE audit_log');
        $r = $this->export();
        $this->assertSame(500, $r->status);
        $this->assertNull($r->file);
        $this->assertSame([], $this->tmpFiles());
    }

    public function test_response_discard_deletes_an_unsent_file(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'dl');
        file_put_contents((string) $f, 'x');
        $r = Response::tempFile((string) $f, ['X-A' => 'b']);
        $this->assertSame('1', $r->headers['Content-Length']);
        $r->discard();
        $this->assertFileDoesNotExist((string) $f);
    }
}
