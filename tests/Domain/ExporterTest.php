<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Exporter;
use App\Domain\ValidationException;
use App\I18n\Translator;
use App\Repo\ExportRepo;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExportFixture;

/** App\Domain\Exporter: ZIP layout, isolation, secrets, amounts, CSV, PDFs, year filter, limits, temp files. */
final class ExporterTest extends TestCase
{
    private \App\Core\Db $db;
    private ExportFixture $fx;
    private int $now;
    private string $tmp;
    private int $a;
    private int $b;
    private int $ownerA;
    private int $ownerB;
    /** @var array<string, int|string> */
    private array $ids;
    /** @var array<string, int|string> */
    private array $idsB;
    /** @var list<string> */
    private array $codes = [];

    protected function setUp(): void
    {
        $this->db = \TestDb::fresh();
        $this->now = (new \DateTimeImmutable('2026-11-18 10:00:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp();
        $this->fx = new ExportFixture($this->db, $this->now);
        $this->tmp = sys_get_temp_dir() . '/faktura-export-test-' . bin2hex(random_bytes(6));
        $ua = $this->fx->user('owner-alpha@example.com');
        $ub = $this->fx->user('owner-zeta@example.com');
        $this->codes = [...$ua['backup_codes'], ...$ub['backup_codes']];
        $this->ownerA = $ua['id'];
        $this->ownerB = $ub['id'];
        $this->a = $this->fx->company('Alpha Service GmbH', 'active', 'ALPHA');
        $this->b = $this->fx->company('Zeta Fremd AG', 'active', 'ZETA');
        $this->fx->member($this->ownerA, $this->a);
        $this->fx->member($this->ownerB, $this->b);
        $member = $this->fx->user('mitarbeiter-alpha@example.com');
        $this->fx->member($member['id'], $this->a, 'user');
        $this->ids = $this->fx->populate($this->a, $this->ownerA, 'ALPHA');
        $this->idsB = $this->fx->populate($this->b, $this->ownerB, 'ZETA');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rm($f) : @unlink($f);
        }
        @rmdir($this->tmp);
    }

    private function rm(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rm($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    /** @param \Closure(int): array{content: string, filename: string}|null $render */
    private function exporter(?int $company = null, ?int $max = null, ?\Closure $render = null): Exporter
    {
        $company ??= $this->a;
        $owner = $company === $this->a ? $this->ownerA : $this->ownerB;
        $ctx = $this->fx->ctx($company, $owner);
        $docs = new \App\Repo\DocumentRepo($this->db, $ctx);
        $render ??= function (int $id) use ($docs): array {
            $d = $docs->find($id) ?? throw new \RuntimeException('missing');
            return ['content' => '%PDF-1.4 fake ' . $d['number'], 'filename' => \App\Pdf\PdfRenderer::filename((string) $d['number'])];
        };
        return new Exporter($ctx, new ExportRepo($this->db, $ctx), new Translator(dirname(__DIR__, 2) . '/lang'), $render, $this->tmp, fn (): int => $this->now, $max ?? Exporter::MAX_PDFS);
    }

    /** @return array<string, string> entry name => bytes */
    private function unzip(string $path): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        return $out;
    }

    /** @return array<string, string> */
    private function build(array $options = [], ?Exporter $e = null): array
    {
        $path = ($e ?? $this->exporter())->build($options);
        try {
            return $this->unzip($path);
        } finally {
            @unlink($path);
        }
    }

    /** @return mixed */
    private function json(array $zip, string $name): mixed
    {
        $this->assertArrayHasKey($name, $zip);
        $this->assertTrue(mb_check_encoding($zip[$name], 'UTF-8'), "$name is UTF-8");
        return json_decode($zip[$name], true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_zip_layout_and_readme(): void
    {
        $zip = $this->build();
        $names = array_keys($zip);
        sort($names);
        $expected = ['README.txt', 'company.json', 'customers.json', 'documents.json', 'email_log.json', 'invoices.csv', 'items.json', 'numbering.json', 'users.json'];
        foreach (['invoice', 'old_invoice', 'credit', 'dunning', 'offer'] as $k) {
            $expected[] = 'pdf/' . $this->number((int) $this->ids[$k]) . '.pdf';
        }
        sort($expected);
        $this->assertSame($expected, $names);
        $readme = $zip['README.txt'];
        $this->assertStringContainsString('Alpha Service GmbH', $readme);
        $this->assertStringContainsString('2026-11-18 10:00', $readme);
        $this->assertStringContainsString('Passwörter', $readme);
        $this->assertStringContainsString("\r\n", $readme);
    }

    private function number(int $id): string
    {
        return (string) $this->db->one('SELECT number FROM documents WHERE id = ?', [$id])['number'];
    }

    public function test_export_contains_only_own_company_data(): void
    {
        $zip = $this->build();
        $foreign = ['ZETA', 'Zeta', 'zeta', 'owner-zeta', 'Fremd AG'];
        foreach ($zip as $name => $bytes) {
            foreach ($foreign as $needle) {
                $this->assertStringNotContainsString($needle, $bytes, "$name leaks '$needle'");
                $this->assertStringNotContainsString($needle, $name);
            }
        }
        // B's row ids never show up as ids of A's rows: every exported row belongs to A.
        $docs = $this->json($zip, 'documents.json');
        $own = array_map('intval', array_column($this->db->all('SELECT id FROM documents WHERE company_id = ?', [$this->a]), 'id'));
        $this->assertEqualsCanonicalizing($own, array_column($docs, 'id'));
        $this->assertEqualsCanonicalizing(
            array_map('intval', array_column($this->db->all('SELECT id FROM customers WHERE company_id = ?', [$this->a]), 'id')),
            array_column($this->json($zip, 'customers.json'), 'id')
        );
        $this->assertEqualsCanonicalizing(
            array_map('intval', array_column($this->db->all('SELECT id FROM email_log WHERE company_id = ?', [$this->a]), 'id')),
            array_column($this->json($zip, 'email_log.json'), 'id')
        );
        $members = array_column($this->json($zip, 'users.json'), 'email');
        sort($members);
        $this->assertSame(['mitarbeiter-alpha@example.com', 'owner-alpha@example.com'], $members);
        // And the other way round: B's export holds none of A's.
        $zipB = $this->build([], $this->exporter($this->b));
        foreach ($zipB as $name => $bytes) {
            $this->assertStringNotContainsString('ALPHA', $bytes, "$name (B) leaks A");
            $this->assertStringNotContainsString('owner-alpha', $bytes);
        }
    }

    public function test_no_secret_is_in_any_file(): void
    {
        $zip = $this->build();
        $needles = ['smtp_pass_enc', 'password_hash', 'totp_secret', 'totp_last_step', '$argon2', 'argon2', ExportFixture::SMTP_PASSWORD, ExportFixture::USER_PASSWORD,
            'TOTPSECRETPLAINTEXT', 'code_hash', 'backup_code'];
        foreach ($this->db->all('SELECT smtp_pass_enc FROM companies') as $c) {
            $needles[] = (string) $c['smtp_pass_enc'];
        }
        foreach ($this->db->all('SELECT password_hash, totp_secret_enc FROM users') as $u) {
            $needles[] = (string) $u['password_hash'];
            $needles[] = (string) $u['totp_secret_enc'];
        }
        array_push($needles, ...$this->codes);
        foreach ($zip as $name => $bytes) {
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $bytes, "$name contains a secret");
            }
        }
    }

    public function test_users_and_company_json_have_only_allowed_keys(): void
    {
        $zip = $this->build();
        foreach ($this->json($zip, 'users.json') as $u) {
            $this->assertSame(['email', 'id', 'role', 'ui_lang'], $this->sorted(array_keys($u)));
        }
        $company = $this->json($zip, 'company.json');
        $this->assertSame('Alpha Service GmbH', $company['name']);
        $this->assertSame('smtp.alpha.example', $company['smtp_host']);
        $this->assertSame(587, $company['smtp_port']);
        $this->assertTrue($company['smtp_password_set']);
        foreach (['smtp_pass_enc', 'access_status', 'access_until', 'logo_path'] as $k) {
            $this->assertArrayNotHasKey($k, $company);
        }
        $this->assertSame('standard', $company['plan']);
        $this->assertSame($this->a, $company['id']);
    }

    /** @param list<string> $a @return list<string> */
    private function sorted(array $a): array
    {
        sort($a);
        return $a;
    }

    public function test_documents_json_matches_the_database_exactly(): void
    {
        $zip = $this->build();
        $docs = $this->json($zip, 'documents.json');
        $byId = array_column($docs, null, 'id');
        $rows = $this->db->all('SELECT * FROM documents WHERE company_id = ? ORDER BY id', [$this->a]);
        $this->assertCount(count($rows), $docs);
        $this->assertSame(array_map('intval', array_column($rows, 'id')), array_column($docs, 'id'), 'ordered by id');
        foreach ($rows as $row) {
            $d = $byId[(int) $row['id']];
            foreach (['net_minor', 'vat_minor', 'gross_minor'] as $c) {
                $this->assertSame((int) $row[$c], $d[$c], $c);
            }
            foreach (['dunning_level', 'dunning_outstanding_minor', 'dunning_interest_minor', 'dunning_interest_days', 'dunning_interest_rate_bp', 'dunning_flat_fee_minor',
                'dunning_fee_minor', 'dunning_claim_minor', 'related_document_id', 'customer_id'] as $c) {
                $this->assertSame($row[$c] === null ? null : (int) $row[$c], $d[$c], $c);
            }
            foreach (['type', 'status', 'number', 'issue_date', 'due_date', 'valid_until', 'offer_status', 'currency', 'lang', 'dunning_pay_by', 'notes'] as $c) {
                $this->assertSame($row[$c], $d[$c], $c);
            }
            $this->assertArrayNotHasKey('company_id', $d);
            $this->assertArrayNotHasKey('seller_json', $d);
            $this->assertSame($row['seller_json'] === null ? null : json_decode($row['seller_json'], true), $d['seller']);
            $this->assertSame($row['buyer_json'] === null ? null : json_decode($row['buyer_json'], true), $d['buyer']);
            $lines = $this->db->all('SELECT * FROM document_lines WHERE document_id = ? ORDER BY position, id', [$row['id']]);
            $this->assertCount(count($lines), $d['lines']);
            foreach ($lines as $i => $l) {
                $this->assertSame((int) $l['net_minor'], $d['lines'][$i]['net_minor']);
                $this->assertSame((int) $l['qty_milli'], $d['lines'][$i]['qty_milli']);
                $this->assertSame((int) $l['unit_minor'], $d['lines'][$i]['unit_minor']);
                $this->assertSame((int) $l['vat_bp'], $d['lines'][$i]['vat_bp']);
            }
            $pays = $this->db->all('SELECT amount_minor FROM payments WHERE document_id = ?', [$row['id']]);
            $this->assertSame(array_map('intval', array_column($pays, 'amount_minor')), array_column($d['payments'], 'amount_minor'));
        }
        // The worked numbers: invoice 1.428,00 gross, credit note negative, dunning amounts stored in its columns, draft included.
        $this->assertSame(142800, $byId[$this->ids['invoice']]['gross_minor']);
        $this->assertSame([50000], array_column($byId[$this->ids['invoice']]['payments'], 'amount_minor'));
        $this->assertLessThan(0, $byId[$this->ids['credit']]['gross_minor']);
        $this->assertSame(1, $byId[$this->ids['dunning']]['dunning_level']);
        $this->assertSame('draft', $byId[$this->ids['draft']]['status']);
        $this->assertNull($byId[$this->ids['draft']]['number']);
        $this->assertSame('ALPHA', substr((string) $byId[$this->ids['invoice']]['buyer']['name'], 6, 5));
    }

    public function test_other_json_files_are_complete_and_typed(): void
    {
        $zip = $this->build();
        $items = $this->json($zip, 'items.json');
        $this->assertCount(1, $items);
        $this->assertSame(10000, $items[0]['price_minor']);
        $this->assertSame(['de', 'en', 'pl'], $this->sorted(array_column($items[0]['names'], 'lang')));
        $log = $this->json($zip, 'email_log.json');
        $this->assertCount(1, $log);
        $this->assertSame('kunde-alpha@example.com', $log[0]['to_email']);
        $num = $this->json($zip, 'numbering.json');
        $this->assertNotEmpty($num['counters']);
        $this->assertSame(count($this->db->all('SELECT 1 FROM number_counters WHERE company_id = ?', [$this->a])), count($num['counters']));
        $this->assertArrayHasKey('formats', $num);
        $customers = $this->json($zip, 'customers.json');
        $this->assertSame('Kunde ALPHA GmbH', $customers[0]['name']);
        $this->assertIsInt($customers[0]['id']);
    }

    // ---- CSV ----

    /** @return list<list<string>> rows including the header */
    private function csv(array $zip): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $zip['invoices.csv'], 'UTF-8 BOM');
        $body = substr($zip['invoices.csv'], 3);
        $this->assertStringEndsWith("\r\n", $body);
        $unquoted = (string) preg_replace('/"(?:[^"]|"")*"/', '', $body);
        $this->assertSame(substr_count($unquoted, "\n"), substr_count($unquoted, "\r\n"), 'every record ends in CRLF');
        $rows = [];
        $h = fopen('php://memory', 'w+');
        fwrite($h, $body);
        rewind($h);
        while (($r = fgetcsv($h, 0, ';', '"', '')) !== false) {
            $rows[] = $r;
        }
        fclose($h);
        return $rows;
    }

    public function test_csv_rows_and_amounts(): void
    {
        $rows = $this->csv($this->build());
        $this->assertSame(['Nummer', 'Typ', 'Datum', 'Fällig', 'Kunde', 'Währung', 'Netto', 'USt', 'Brutto', 'Bezahlt', 'Offen', 'Status'], $rows[0]);
        array_shift($rows);
        $this->assertCount(3, $rows, 'two invoices and one credit note: no offer, dunning or draft');
        $by = array_column($rows, null, 0);
        $inv = $by[$this->number((int) $this->ids['invoice'])];
        $this->assertSame(['Rechnung', '2026-10-14', '2026-10-28', 'Kunde ALPHA GmbH', 'EUR', '1200,00', '228,00', '1428,00', '500,00', '928,00', 'überfällig'], array_slice($inv, 1));
        $old = $by[$this->number((int) $this->ids['old_invoice'])];
        $this->assertSame(['Rechnung', '2025-03-10', '2025-03-24', 'Kunde ALPHA GmbH', 'EUR', '50,00', '9,50', '59,50', '0,00', '0,00', 'gutgeschrieben'], array_slice($old, 1));
        $cn = $by[$this->number((int) $this->ids['credit'])];
        $this->assertSame(['Gutschrift', '2026-11-18', '', 'Kunde ALPHA GmbH', 'EUR', '-50,00', '-9,50', '-59,50', '0,00', '0,00', 'Gutschrift'], array_slice($cn, 1));
    }

    public function test_csv_amounts_equal_the_json_minor_units(): void
    {
        $zip = $this->build();
        $docs = array_column($this->json($zip, 'documents.json'), null, 'number');
        $rows = $this->csv($zip);
        array_shift($rows);
        foreach ($rows as $r) {
            $d = $docs[$r[0]];
            $this->assertSame(Exporter::amount($d['net_minor']), $r[6]);
            $this->assertSame(Exporter::amount($d['vat_minor']), $r[7]);
            $this->assertSame(Exporter::amount($d['gross_minor']), $r[8]);
            $this->assertMatchesRegularExpression('/\A-?[0-9]+,[0-9]{2}\z/', $r[8]);
        }
    }

    /** @return iterable<string, array{0: int, 1: string}> */
    public static function amounts(): iterable
    {
        yield 'zero' => [0, '0,00'];
        yield 'cents' => [5, '0,05'];
        yield 'negative cents' => [-5, '-0,05'];
        yield 'one' => [100, '1,00'];
        yield 'big' => [123456789, '1234567,89'];
        yield 'negative' => [-142800, '-1428,00'];
        yield 'int max' => [PHP_INT_MAX, '92233720368547758,07'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('amounts')]
    public function test_amount_format_uses_integer_arithmetic(int $minor, string $expected): void
    {
        $this->assertSame($expected, Exporter::amount($minor));
    }

    public function test_csv_injection_is_neutralised_and_special_characters_quoted(): void
    {
        $names = ['=HYPERLINK("http://evil","x")', '@SUM(A1)', '+1+1', '-2+3', "\tTabbed", "\rCarriage", 'Normal; with "quotes"', "Line\nbreak"];
        foreach ($names as $i => $name) {
            $c = $this->fx->customer($this->a, $this->ownerA, $name, "inj$i@example.com");
            $svc = $this->fx->service($this->a, $this->ownerA);
            $id = $svc->createDraft('invoice', ['customer_id' => $c, 'issue_date' => '2026-11-0' . ($i + 1), 'due_date' => '2026-11-30', 'lines' => ExportFixture::lines('INJ')]);
            $svc->issue($id, new \DateTimeImmutable('@' . $this->now));
            // The customer form trims leading whitespace, so plant the raw name in the issued snapshot (as an old or imported one could be).
            $this->db->exec('UPDATE documents SET buyer_json = ? WHERE id = ?', [json_encode(['name' => $name, 'address' => 'Weg 1']), $id]);
        }
        $zip = $this->build();
        $rows = $this->csv($zip);
        $customers = array_column($rows, 4);
        foreach (['=HYPERLINK("http://evil","x")', '@SUM(A1)', '+1+1', '-2+3', "\tTabbed", "\rCarriage"] as $evil) {
            $this->assertContains("'" . $evil, $customers, 'prefixed with a single quote');
            $this->assertNotContains($evil, $customers);
        }
        $this->assertContains('Normal; with "quotes"', $customers, 'quoting round-trips');
        $this->assertContains("Line\nbreak", $customers);
        // No cell of the file starts with a formula character, except the negative amounts we generate ourselves.
        foreach ($rows as $i => $r) {
            foreach ($r as $col => $cell) {
                if ($i > 0 && in_array($col, [6, 7, 8, 9, 10], true)) {
                    $this->assertMatchesRegularExpression('/\A-?[0-9]+,[0-9]{2}\z/', $cell);
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression('/\A[=+\-@\t\r]/', $cell, "cell [$i][$col]");
            }
        }
        // The JSON keeps the original name untouched.
        $buyers = array_column(array_column($this->json($zip, 'documents.json'), 'buyer'), 'name');
        $this->assertContains('=HYPERLINK("http://evil","x")', $buyers);
    }

    // ---- PDFs ----

    public function test_pdfs_for_every_issued_document_and_none_for_drafts(): void
    {
        $zip = $this->build();
        $pdfs = array_filter(array_keys($zip), fn (string $n): bool => str_starts_with($n, 'pdf/'));
        $this->assertCount(5, $pdfs);
        foreach ($pdfs as $n) {
            $this->assertStringStartsWith('%PDF-', $zip[$n]);
            $this->assertMatchesRegularExpression('#\Apdf/[A-Za-z0-9._-]+\.pdf\z#', $n);
        }
        $draftCount = (int) $this->db->one("SELECT COUNT(*) AS c FROM documents WHERE company_id = ? AND status = 'draft'", [$this->a])['c'];
        $this->assertSame(1, $draftCount);
        foreach ($pdfs as $n) {
            $this->assertStringNotContainsString('Entwurf', $zip[$n]);
        }
    }

    public function test_include_pdfs_false_has_no_pdf_directory(): void
    {
        $zip = $this->build(['include_pdfs' => false]);
        $this->assertSame([], array_values(array_filter(array_keys($zip), fn (string $n): bool => str_contains($n, 'pdf'))));
        $this->assertArrayHasKey('documents.json', $zip);
    }

    public function test_colliding_pdf_names_get_the_type_prefix(): void
    {
        $e = $this->exporter(render: fn (int $id): array => ['content' => '%PDF-1.4 ' . $id, 'filename' => 'SAME.pdf']);
        $zip = $this->build([], $e);
        $pdfs = array_values(array_filter(array_keys($zip), fn (string $n): bool => str_starts_with($n, 'pdf/')));
        $this->assertCount(5, $pdfs);
        $this->assertCount(5, array_unique(array_map('strtolower', $pdfs)));
        $this->assertContains('pdf/SAME.pdf', $pdfs);
        $this->assertNotEmpty(array_filter($pdfs, fn (string $n): bool => str_starts_with($n, 'pdf/invoice-')));
    }

    public function test_year_filter_keeps_only_issued_documents_of_that_year(): void
    {
        $zip = $this->build(['year' => 2025]);
        $docs = $this->json($zip, 'documents.json');
        $this->assertSame([$this->ids['old_invoice']], array_column($docs, 'id'), 'the credit note is dated today (2026)');
        $this->assertCount(1, array_filter(array_keys($zip), fn (string $n): bool => str_starts_with($n, 'pdf/')));
        $this->assertCount(2, $this->csv($zip), 'header and the one invoice');
    }

    public function test_year_filter_2026_excludes_2025_and_drafts(): void
    {
        $zip = $this->build(['year' => '2026']);
        $docs = $this->json($zip, 'documents.json');
        $ids = array_column($docs, 'id');
        $this->assertNotContains($this->ids['old_invoice'], $ids);
        $this->assertNotContains($this->ids['draft'], $ids);
        $this->assertContains($this->ids['invoice'], $ids);
        foreach ($docs as $d) {
            $this->assertSame('issued', $d['status']);
            $this->assertSame('2026', substr($d['issue_date'], 0, 4));
        }
        $pdfs = array_filter(array_keys($zip), fn (string $n): bool => str_starts_with($n, 'pdf/'));
        $this->assertCount(count($docs), $pdfs, 'PDF count matches');
        $this->assertSame(count($docs), $this->exporterStats(['year' => 2026])['doc_count']);
        foreach ($this->csv($zip) as $i => $r) {
            if ($i > 0) {
                $this->assertSame('2026', substr($r[2], 0, 4));
            }
        }
        // customers/items stay complete, the README names the scope
        $this->assertCount(1, $this->json($zip, 'customers.json'));
        $this->assertStringContainsString('2026', $zip['README.txt']);
    }

    /** @return array{doc_count: int, pdf_count: int} */
    private function exporterStats(array $options): array
    {
        $e = $this->exporter();
        @unlink($e->build($options));
        return $e->stats();
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function badYears(): iterable
    {
        foreach (['20x6', '202', '99999', 'abc', '2026 ', '-2026', '1969', '2026-01', ' 2026', "2026\n", '１２３４'] as $y) {
            yield "'" . addcslashes($y, "\n") . "'" => [$y];
        }
        yield 'array' => [[2026]];
        yield 'float' => [2026.5];
        yield 'bool' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badYears')]
    public function test_invalid_year_is_a_validation_exception(mixed $year): void
    {
        try {
            $this->exporter()->build(['year' => $year]);
            $this->fail('accepted');
        } catch (ValidationException $e) {
            $this->assertSame(['year' => 'export.err_year'], $e->errors);
        }
        $this->assertSame([], glob($this->tmp . '/*') ?: [], 'nothing left behind');
    }

    public function test_too_many_pdfs_is_refused_before_any_work(): void
    {
        $e = $this->exporter(max: 4);
        try {
            $e->build();
            $this->fail('accepted');
        } catch (ValidationException $ex) {
            $this->assertSame(['pdfs' => 'export.err_too_many_pdfs'], $ex->errors);
        }
        $this->assertFalse(is_dir($this->tmp) && (glob($this->tmp . '/*') ?: []) !== [], 'no temp files');
        // Exactly at the limit is fine, and without PDFs the limit does not apply.
        $this->assertCount(5, array_filter(array_keys($this->build([], $this->exporter(max: 5))), fn (string $n): bool => str_starts_with($n, 'pdf/')));
        $this->assertArrayHasKey('documents.json', $this->build(['include_pdfs' => false], $this->exporter(max: 0)));
        // A year with fewer PDFs fits under the limit.
        $this->assertArrayHasKey('documents.json', $this->build(['year' => 2025], $this->exporter(max: 2)));
    }

    public function test_the_default_limit_is_400_pdfs(): void
    {
        $this->assertSame(400, Exporter::MAX_PDFS);
    }

    // ---- scale, suspended, temp files ----

    public function test_documents_are_paged_and_all_exported_in_id_order(): void
    {
        $this->db->exec('BEGIN');
        for ($i = 1; $i <= 450; $i++) {
            $this->db->exec(
                "INSERT INTO documents (company_id, type, status, number, currency, net_minor, vat_minor, gross_minor, issue_date, due_date, buyer_name, buyer_json, seller_json, issued_at) VALUES (?, 'invoice', 'issued', ?, 'EUR', 100, 19, 119, '2024-05-01', '2024-05-15', 'Bulk', '{\"name\":\"Bulk\"}', '{}', '2024-05-01 10:00:00')",
                [$this->a, 'BULK-' . $i]
            );
        }
        $this->db->exec('COMMIT');
        $zip = $this->build(['include_pdfs' => false]);
        $docs = $this->json($zip, 'documents.json');
        $this->assertCount(450 + 6, $docs);
        $ids = array_column($docs, 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
        $this->assertCount(450 + 3, $this->csv($zip) ? array_slice($this->csv($zip), 1) : []);
    }

    public function test_suspended_company_can_export(): void
    {
        $this->db->exec("UPDATE companies SET access_status = 'suspended' WHERE id = ?", [$this->a]);
        $this->assertFalse($this->fx->ctx($this->a, $this->ownerA)->canIssue(new \DateTimeImmutable('@' . $this->now)));
        $zip = $this->build();
        $this->assertCount(6, $this->json($zip, 'documents.json'));
        $this->assertArrayHasKey('pdf/' . $this->number((int) $this->ids['invoice']) . '.pdf', $zip);
        $this->assertArrayNotHasKey('access_status', $this->json($zip, 'company.json'));
    }

    public function test_temp_file_is_private_unique_and_the_work_directory_is_gone(): void
    {
        $e = $this->exporter();
        $p1 = $e->build();
        $p2 = $e->build();
        try {
            $this->assertNotSame($p1, $p2);
            $this->assertSame($this->tmp, dirname($p1));
            $this->assertMatchesRegularExpression('#/export-[0-9a-f]{24}\.zip\z#', $p1);
            $this->assertSame(0600, fileperms($p1) & 0777);
            $this->assertSame(0700, fileperms($this->tmp) & 0777);
            $left = glob($this->tmp . '/*') ?: [];
            sort($left);
            $this->assertSame($this->sorted([$p1, $p2]), $left, 'only the two zips remain (no work dirs)');
            $this->assertSame(['doc_count' => 6, 'pdf_count' => 5], $e->stats());
        } finally {
            @unlink($p1);
            @unlink($p2);
        }
    }

    public function test_a_failure_removes_every_temp_file(): void
    {
        $calls = 0;
        $e = $this->exporter(render: function (int $id) use (&$calls): array {
            if (++$calls === 3) {
                throw new \RuntimeException('renderer exploded');
            }
            return ['content' => '%PDF-1.4 x', 'filename' => 'f' . $id . '.pdf'];
        });
        try {
            $e->build();
            $this->fail('should have thrown');
        } catch (\RuntimeException $ex) {
            $this->assertSame('renderer exploded', $ex->getMessage());
        }
        $this->assertSame([], glob($this->tmp . '/*') ?: [], 'no zip and no work directory left');
    }

    public function test_stale_leftovers_are_purged_on_the_next_export(): void
    {
        mkdir($this->tmp, 0700, true);
        file_put_contents($this->tmp . '/export-old.zip', 'x');
        touch($this->tmp . '/export-old.zip', time() - 7200);
        file_put_contents($this->tmp . '/export-fresh.zip', 'x');
        file_put_contents($this->tmp . '/unrelated.txt', 'x');
        touch($this->tmp . '/unrelated.txt', time() - 7200);
        @unlink($this->exporter()->build());
        $this->assertFileDoesNotExist($this->tmp . '/export-old.zip');
        $this->assertFileExists($this->tmp . '/export-fresh.zip', 'a recent file may belong to a download in progress');
        $this->assertFileExists($this->tmp . '/unrelated.txt', 'only export-* files are touched');
    }

    public function test_the_time_limit_call_never_breaks_the_export_when_disabled(): void
    {
        // set_time_limit exists but may be disabled in production; the export must not depend on it.
        $this->assertArrayHasKey('README.txt', $this->build());
    }
}
