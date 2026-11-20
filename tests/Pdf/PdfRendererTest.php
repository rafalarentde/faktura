<?php
declare(strict_types=1);

namespace Tests\Pdf;

use App\Domain\DocumentNotFound;
use App\Pdf\PdfNotAvailable;
use App\Pdf\PdfRenderer;
use App\Storage\LogoStore;
use App\Vies\ViesStatus;
use FontLib\Font;
use PHPUnit\Framework\TestCase;

/**
 * Server-side PDF of issued invoices and credit notes (Task 17). Dompdf is slow, so the documents
 * are issued and rendered once per class (setUpBeforeClass) and the tests read the shared PDFs.
 * Text is extracted with pdftotext (poppler), so every assertion is about what the customer sees.
 */
final class PdfRendererTest extends TestCase
{
    private const GLYPHS = 'ąćęłńóśźżĄĆĘŁŃÓŚŹŻßäöüÄÖÜ€zł$';
    private const INJECTIONS = [
        '<script>alert(1)</script>', '<img src="http://evil.example/x">', '</style><style>body{display:none}</style>',
    ];

    private static PdfFixture $f;
    /** @var array<string, int> */
    private static array $ids = [];
    /** @var array<string, string> rendered PDFs by fixture name */
    private static array $pdf = [];
    /** @var list<string> URLs opened through the http(s) stream wrappers while rendering */
    public static array $fetched = [];

    public static function setUpBeforeClass(): void
    {
        $f = self::$f = new PdfFixture();
        $a = $f->companyA;

        // Company A has a PNG logo (stored through LogoStore like an upload).
        $png = self::png(320, 90);
        $store = new LogoStore($f->storage);
        $clean = $store->process($png);
        unlink($png);
        $f->db->exec('UPDATE companies SET logo_path = ? WHERE id = ?', [$store->save($a, $clean['bytes'], $clean['ext']), $a]);

        $de = $f->customer($a);
        $self = self::$ids;
        $self['domestic'] = $f->issued($a, $de);

        $glyphs = $f->customer($a, ['name' => 'Łukasz Żółć & Söhne Gärtnerei', 'country' => 'PL', 'vat_id' => '',
            'address' => "ul. Świętokrzyska 5\n00-950 Warszawa"]);
        $self['glyphs'] = $f->issued($a, $glyphs, ['lines' => [
            PdfFixture::line(['name' => 'Größe ÄÖÜ äöü ß', 'description' => 'Zażółć gęślą jaźń ĄĆĘŁŃÓŚŹŻ', 'unit_minor' => 123456]),
            PdfFixture::line(['name' => 'Übergröße Maßband', 'qty_milli' => 2500, 'unit' => 'piece', 'unit_minor' => 999, 'discount_bp' => 1000]),
        ]]);

        $pl = $f->customer($a, ['name' => 'Kowalski Transport Sp. z o.o.', 'address' => "ul. Długa 12\n00-238 Warszawa", 'country' => 'PL',
            'vat_id' => 'PL1234567890', 'default_lang' => 'pl', 'default_currency' => 'PLN', 'payment_days' => '30'], ViesStatus::Valid);
        $self['rc_pl'] = $f->issued($a, $pl);
        $self['rc_de'] = $f->issued($a, $pl, ['lang' => 'de', 'currency' => 'EUR']);
        $self['rc_en'] = $f->issued($a, $pl, ['lang' => 'en', 'currency' => 'EUR']);

        $private = $f->customer($a, ['name' => 'Jan Kowalski', 'country' => 'PL', 'vat_id' => '', 'is_company' => '0',
            'default_lang' => 'de', 'default_currency' => 'PLN']);
        $self['pln'] = $f->issued($a, $private, ['currency' => 'PLN', 'eur_rate' => '4.2650']);

        $us = $f->customer($a, ['name' => 'Acme Inc', 'address' => "1 Infinite Loop\nCupertino, CA 95014", 'country' => 'US', 'vat_id' => '',
            'default_lang' => 'en', 'default_currency' => 'USD']);
        $self['usd'] = $f->issued($a, $us, ['currency' => 'USD', 'eur_rate' => '1.0870']);

        $self['credit'] = $f->creditNote($a, $self['domestic'], [PdfFixture::line(['name' => 'Gutschrift Anfahrt', 'unit_minor' => 6000])]);

        $long = $f->customer($a, [
            'name' => str_repeat('Überlange Firmenbezeichnung Żółć ', 5) . 'GmbH & Co. KG',
            'address' => "Sehr lange Straßenbezeichnung mit Hinterhof und Seitenflügel, Aufgang C, 4. Obergeschoss links\nPostfach 12 34 56\n12345 Großstadt-Nord",
        ]);
        $lines = [];
        for ($i = 1; $i <= 32; $i++) {
            $lines[] = PdfFixture::line(['name' => sprintf('Leistung Nr. %02d', $i), 'unit_minor' => 1000 * $i,
                'description' => $i % 5 === 0 ? str_repeat('Sehr lange Beschreibung der Leistung mit vielen Wörtern. ', 8) . 'Ende ' . $i : '']);
        }
        $self['long'] = $f->issued($a, $long, ['lines' => $lines]);

        // Company B: no logo, hostile strings everywhere.
        $b = $f->companyB;
        $f->db->exec('UPDATE companies SET name = ?, address = ? WHERE id = ?',
            ['Fremd & <Partner> AG "Q"', "Weg 1\n" . self::INJECTIONS[1], $b]);
        $evil = $f->customer($b, ['name' => '<script>alert(1)</script> & Co "q" \'a\'', 'address' => self::INJECTIONS[1] . "\n" . self::INJECTIONS[2]]);
        $self['evil'] = $f->issued($b, $evil, [
            'intro_text' => self::INJECTIONS[2], 'outro_text' => 'Ende & <b>fett</b>',
            'lines' => [PdfFixture::line(['name' => '<b>Fett</b> & "Zitat"', 'description' => "url(http://evil.example/y) <i>x</i>\n</td></tr></table>"])],
        ]);
        self::$ids = $self;

        // Every render runs with http/https replaced by recorders: nothing may be fetched.
        self::$fetched = [];
        foreach (['http', 'https'] as $w) {
            stream_wrapper_unregister($w);
            stream_wrapper_register($w, RecordingStreamWrapper::class);
        }
        try {
            foreach ($self as $name => $id) {
                self::$pdf[$name] = $f->renderer($name === 'evil' ? $b : $a)->render($id);
            }
        } finally {
            foreach (['http', 'https'] as $w) {
                stream_wrapper_restore($w);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$f->cleanup();
    }

    protected function setUp(): void
    {
        if (!PdfFixture::hasPoppler()) {
            $this->markTestSkipped('pdftotext/pdfinfo (poppler-utils) are needed to read the rendered PDFs.');
        }
    }

    private static function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 15, 15, 15));
        imagefilledrectangle($im, 20, 20, $w - 20, $h - 20, imagecolorallocate($im, 245, 196, 0));
        $file = tempnam(sys_get_temp_dir(), 'logo-');
        imagepng($im, $file);
        imagedestroy($im);
        return $file;
    }

    private function text(string $name): string
    {
        return PdfFixture::text(self::$pdf[$name]);
    }

    private function flat(string $name): string
    {
        return PdfFixture::flat(self::$pdf[$name]);
    }

    private function html(string $name): string
    {
        $company = $name === 'evil' ? self::$f->companyB : self::$f->companyA;
        return self::$f->renderer($company)->html(self::$ids[$name]);
    }

    // ---- fonts ----

    public function test_fonts_cover_polish_and_german_glyphs(): void
    {
        $files = glob(PdfFixture::ROOT . '/resources/fonts/*.ttf');
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $font = Font::load($file);
            $font->parse();
            $map = [];
            foreach ($font->getData('cmap')['subtables'] as $sub) {
                $map += $sub['glyphIndexArray'] ?? [];
            }
            foreach (mb_str_split(self::GLYPHS) as $ch) {
                $this->assertNotEmpty($map[mb_ord($ch)] ?? 0, sprintf('%s lacks %s (U+%04X)', basename($file), $ch, mb_ord($ch)));
            }
            $this->assertNotEmpty($map[0x00A0] ?? 0, basename($file) . ' lacks NBSP (number formatting)');
            $this->assertArrayNotHasKey('fvar', $font->getTable(), basename($file) . ' is a variable font (Dompdf cannot pick weights)');
            $font->close();
        }
        // The fonts registered with Dompdf are exactly the files checked above.
        $registered = array_values(array_unique(array_column(PdfRenderer::FONTS, 'file')));
        sort($registered);
        $this->assertSame(array_map('basename', $files), $registered);
    }

    public function test_pdf_embeds_only_the_registered_fonts(): void
    {
        foreach (self::$pdf as $name => $pdf) {
            $fonts = PdfFixture::fonts($pdf);
            $this->assertMatchesRegularExpression('/SourceSans3-Regular/', $fonts, $name);
            $this->assertMatchesRegularExpression('/SourceSans3-Bold/', $fonts, $name);
            $this->assertMatchesRegularExpression('/BarlowCondensed/', $fonts, $name);
            $this->assertDoesNotMatchRegularExpression('/Times|Helvetica|Courier|DejaVu|Symbol/', $fonts, "$name falls back to a core font:\n$fonts");
            foreach (array_slice(explode("\n", trim($fonts)), 2) as $row) {
                $this->assertMatchesRegularExpression('/\byes\s+yes\s+yes\b/', $row, "$name: font not embedded/subset/unicode: $row");
            }
        }
    }

    // ---- content ----

    public function test_render_returns_valid_pdf(): void
    {
        $pdf = self::$pdf['glyphs'];
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringEndsWith('%%EOF', rtrim($pdf));
        $t = $this->text('glyphs');
        foreach (['RECHNUNG', 'RE-2026-002', 'Łukasz Żółć & Söhne Gärtnerei', 'ul. Świętokrzyska 5', '00-950 Warszawa', 'Polen',
            'Größe ÄÖÜ äöü ß', 'Zażółć gęślą jaźń ĄĆĘŁŃÓŚŹŻ', 'Übergröße Maßband', '1.234,56 €', '22,48 €', '10 %', '1.257,04 €',
            'USt 19 %', '238,84 €', '1.495,88 €', 'Muster Service GmbH', 'DE987654321', '27/123/45678', 'DE89370400440532013000',
            'COBADEFFXXX', 'Commerzbank Berlin', 'Rechnungsdatum', '18.11.2026', 'Leistungsdatum', '17.11.2026', 'Fällig am', '02.12.2026',
            'Zahlbar bis 02.12.2026 ohne Abzug.', 'Vielen Dank für deinen Auftrag.', 'Mit freundlichen Grüßen', 'RABATT %', 'Seite 1 von 1'] as $s) {
            $this->assertStringContainsString($s, $t);
        }
        $info = PdfFixture::info($pdf);
        $this->assertSame('Rechnung RE-2026-002', $info['Title'] ?? null);
        $this->assertSame('Muster Service GmbH', $info['Author'] ?? null);
        $this->assertSame('Faktura', $info['Creator'] ?? null);
        $this->assertMatchesRegularExpression('/^595\.\d+ x 841\.\d+ pts \(A4\)/', $info['Page size'] ?? '');
    }

    public function test_domestic_invoice_lines_and_totals(): void
    {
        $t = $this->text('domestic');
        foreach (['RE-2026-001', 'Bauhaus Logistik GmbH', 'Hauptstraße 5', '80331 München', 'USt-IdNr.: DE123456789', 'Beratung / Workshop',
            'Anfahrtspauschale', 'Schulungsunterlagen', '2 Std.', '1 pauschal', '5 Stk.', '450,00 €', '900,00 €', '1.020,00 €', 'USt 19 %',
            '182,40 €', 'USt 7 %', '4,20 €', '1.206,60 €'] as $s) {
            $this->assertStringContainsString($s, $t);
        }
        $this->assertStringNotContainsString('RABATT', $t, 'no discount column without discounts');
        $this->assertStringNotContainsString('USt in EUR', $t);
        $this->assertStringNotContainsString('Reverse', $t);
    }

    public function test_reverse_charge_note_present_in_selected_language(): void
    {
        $tr = self::$f->translator();
        foreach (['de' => 'rc_de', 'pl' => 'rc_pl', 'en' => 'rc_en'] as $lang => $name) {
            $note = (string) preg_replace('/\s+/u', ' ', $tr->t('invoice.reverse_charge_note', $lang));
            $this->assertStringContainsString($note, $this->flat($name), $lang);
            foreach (['de', 'pl', 'en'] as $other) {
                if ($other !== $lang) {
                    $this->assertStringNotContainsString(
                        (string) preg_replace('/\s+/u', ' ', $tr->t('invoice.reverse_charge_note', $other)), $this->flat($name)
                    );
                }
            }
        }
        $this->assertStringContainsString('1 020,00 zł', $this->text('rc_pl'));
        $this->assertStringContainsString('VAT 0 %', $this->text('rc_pl'));
    }

    public function test_reverse_charge_shows_both_vat_ids(): void
    {
        foreach (['rc_de', 'rc_pl', 'rc_en'] as $name) {
            $t = $this->text($name);
            $this->assertStringContainsString('PL1234567890', $t, $name);
            $this->assertStringContainsString('DE987654321', $t, $name);
        }
    }

    public function test_pln_invoice_shows_vat_in_eur_and_rate(): void
    {
        $t = $this->flat('pln');
        $this->assertStringContainsString('1.206,60 PLN', $t);
        $this->assertStringContainsString('USt in EUR: 43,75 €', $t);
        $this->assertStringContainsString('Umrechnungskurs: 1 EUR = 4,2650 PLN', $t);
        $this->assertStringNotContainsString('Reverse', $t);
    }

    public function test_foreign_currency_usd_in_english(): void
    {
        $t = $this->flat('usd');
        foreach (['INVOICE', 'Acme Inc', 'United States', 'US$1,206.60', 'VAT in EUR: €171.67', 'Exchange rate: 1 EUR = 1.0870 USD',
            'Invoice date', '18/11/2026', 'Payable by 02/12/2026 without deduction.', 'Page 1 of 1', 'Bank details'] as $s) {
            $this->assertStringContainsString($s, $t);
        }
    }

    public function test_labels_in_each_language(): void
    {
        $this->assertStringContainsString('FAKTURA', $this->text('rc_pl'));
        $this->assertStringContainsString('NABYWCA', $this->text('rc_pl'));
        $this->assertStringContainsString('Strona 1 z 1', $this->text('rc_pl'));
        $this->assertStringContainsString('Dane do przelewu', $this->text('rc_pl'));
        $this->assertStringContainsString('Płatne do 02.12.2026 bez potrąceń.', $this->flat('rc_pl'));
        $this->assertStringContainsString('RECHNUNGSEMPFÄNGER', $this->text('rc_de'));
        $this->assertStringContainsString('Seite 1 von 1', $this->text('rc_de'));
        $this->assertStringContainsString('BILL TO', $this->text('rc_en'));
        $this->assertStringContainsString('INVOICE', $this->text('rc_en'));
    }

    public function test_credit_note_pdf(): void
    {
        $t = $this->flat('credit');
        foreach (['RECHNUNGSKORREKTUR', 'KO-2026-001', 'Korrektur zur Rechnung RE-2026-001 vom 18.11.2026', 'Gutschrift Anfahrt',
            '60,00 €', '-60,00 €', '-11,40 €', '-71,40 €', 'Bauhaus Logistik GmbH'] as $s) {
            $this->assertStringContainsString($s, $t);
        }
        $this->assertStringNotContainsString('Zahlbar bis', $t);
        $this->assertStringNotContainsString('Fällig am', $t);
        $this->assertSame('Rechnungskorrektur KO-2026-001', PdfFixture::info(self::$pdf['credit'])['Title'] ?? null);
    }

    public function test_long_customer_name_and_30_lines_paginate(): void
    {
        $pdf = self::$pdf['long'];
        $pages = (int) (PdfFixture::info($pdf)['Pages'] ?? 0);
        $this->assertGreaterThanOrEqual(2, $pages);
        $t = $this->text('long');
        $flat = $this->flat('long');
        for ($i = 1; $i <= 32; $i++) {
            $this->assertStringContainsString(sprintf('Leistung Nr. %02d', $i), $t);
        }
        $this->assertStringContainsString('Ende 30', $flat);
        $this->assertStringContainsString('GmbH & Co. KG', $flat);
        $this->assertStringContainsString('Überlange Firmenbezeichnung Żółć', $flat);
        $this->assertStringContainsString('12345 Großstadt-Nord', $t);
        // 32 lines, 1000..32000 cents: net 5.280,00, VAT 1.003,20, gross 6.283,20
        $this->assertStringContainsString('5.280,00 €', $t);
        $this->assertStringContainsString('1.003,20 €', $t);
        $this->assertStringContainsString('6.283,20 €', $t);
        // The table header repeats on every page that carries lines; every page has the footer.
        $perPage = explode("\f", $t);
        $withLines = 0;
        for ($p = 1; $p <= $pages; $p++) {
            if (str_contains($perPage[$p - 1], 'Leistung Nr.')) {
                $withLines++;
                $this->assertStringContainsString('BESCHREIBUNG', $perPage[$p - 1], "table header repeated on page $p");
            }
            $this->assertStringContainsString("Seite $p von $pages", $perPage[$p - 1], "footer page $p");
            $this->assertStringContainsString('DE89370400440532013000', $perPage[$p - 1], "bank data in footer page $p");
        }
        $this->assertGreaterThanOrEqual(2, $withLines);
    }

    public function test_logo_is_embedded_as_data_uri_when_present(): void
    {
        $html = $this->html('domestic');
        $this->assertMatchesRegularExpression('#<img class="logo" src="data:image/png;base64,[A-Za-z0-9+/=]+"#', $html);
        $this->assertStringNotContainsString('storage/', $html);
        $this->assertStringNotContainsString('/logos/', $html);
        $this->assertMatchesRegularExpression('/^\s*1\s+0\s+image\s+320\s+90/m', PdfFixture::images(self::$pdf['domestic']));
    }

    public function test_without_logo_the_company_name_is_shown_as_text_block(): void
    {
        $html = $this->html('evil');
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<div class="logo-text">Fremd &amp; &lt;Partner&gt; AG &quot;Q&quot;</div>', $html);
        $rows = array_filter(explode("\n", PdfFixture::images(self::$pdf['evil'])), fn (string $l): bool => trim($l) !== '');
        $this->assertCount(2, $rows, 'pdfimages lists only its header: no images');
    }

    // ---- escaping and security ----

    public function test_html_escapes_customer_name(): void
    {
        $html = $this->html('evil');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co &quot;q&quot; &#039;a&#039;', $html);
        $this->assertSame(1, substr_count($html, '<style'), 'only the template style block');
        $this->assertSame(1, substr_count($html, '</style>'));
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>Fett', $html);
        $this->assertStringNotContainsString('<b>fett', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringNotContainsString('</td></tr></table>', $html);
        $this->assertStringContainsString('&lt;img src=&quot;http://evil.example/x&quot;&gt;', $html);

        $t = $this->flat('evil');
        foreach (['<script>alert(1)</script> & Co "q" \'a\'', '<img src="http://evil.example/x">', '</style><style>body{display:none}</style>',
            '<b>Fett</b> & "Zitat"', 'url(http://evil.example/y) <i>x</i>', '</td></tr></table>', 'Ende & <b>fett</b>', 'Fremd & <Partner> AG "Q"'] as $s) {
            $this->assertStringContainsString($s, $t, 'shown literally in the PDF');
        }
        $this->assertSame([], self::$fetched, 'Dompdf must not fetch anything');
    }

    public function test_dompdf_is_locked_down(): void
    {
        $o = self::$f->renderer(self::$f->companyA)->options();
        $this->assertFalse($o->getIsRemoteEnabled());
        $this->assertFalse($o->getIsPhpEnabled());
        $this->assertFalse($o->getIsJavascriptEnabled());
        $this->assertSame(['data://', 'file://'], array_keys($o->getAllowedProtocols()));
        $this->assertSame([realpath(PdfFixture::ROOT . '/resources')], $o->getChroot());
        $this->assertSame(PdfFixture::cacheDir(), $o->getFontDir());
        $this->assertSame(PdfFixture::cacheDir(), $o->getFontCache());
        $this->assertSame(PdfFixture::cacheDir(), $o->getTempDir());
    }

    public function test_filename_is_sanitised(): void
    {
        $this->assertSame('FV_26_11_0042.pdf', PdfRenderer::filename('FV/26/11/0042'));
        $this->assertSame('RE-2026-001.pdf', PdfRenderer::filename('RE-2026-001'));
        $this->assertSame('a_b__c_d_e_f_g.pdf', PdfRenderer::filename("a\"b\r\nc\\d'e;f g"));
        $this->assertSame('.._.._etc_passwd.pdf', PdfRenderer::filename('../../etc/passwd'));
        $this->assertSame('____.pdf', PdfRenderer::filename('Żółć'));
        $this->assertSame('document.pdf', PdfRenderer::filename(''));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9._-]{1,104}\z/', PdfRenderer::filename(str_repeat('x/', 200)));
    }

    // ---- access ----

    public function test_render_draft_is_not_available(): void
    {
        $id = self::$f->draft(self::$f->companyA, self::$f->customer(self::$f->companyA));
        $this->expectException(PdfNotAvailable::class);
        self::$f->renderer(self::$f->companyA)->render($id);
    }

    public function test_html_of_draft_is_not_available_either(): void
    {
        $id = self::$f->draft(self::$f->companyA, self::$f->customer(self::$f->companyA));
        $this->expectException(PdfNotAvailable::class);
        self::$f->renderer(self::$f->companyA)->html($id);
    }

    public function test_other_companys_document_is_not_found(): void
    {
        $this->expectException(DocumentNotFound::class);
        self::$f->renderer(self::$f->companyB)->render(self::$ids['domestic']);
    }

    public function test_unknown_document_and_offers_are_not_found(): void
    {
        $r = self::$f->renderer(self::$f->companyA);
        try {
            $r->render(999999);
            $this->fail('unknown id');
        } catch (DocumentNotFound) {
        }
        $svc = self::$f->service(self::$f->companyA);
        $offer = $svc->createDraft('offer', ['customer_id' => self::$f->customer(self::$f->companyA), 'issue_date' => '2026-11-18',
            'lines' => [PdfFixture::line()]]);
        $svc->issue($offer, new \DateTimeImmutable('2026-11-18 10:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->expectException(DocumentNotFound::class);
        $r->html($offer);
    }

    public function test_pdf_is_built_from_snapshots(): void
    {
        $f = self::$f;
        $c = $f->customer($f->companyA, ['name' => 'Alter Name GmbH', 'address' => "Altstraße 1\n01067 Dresden"]);
        $id = $f->issued($f->companyA, $c);
        $f->db->exec("UPDATE customers SET name = 'Neuer Name AG', address = 'Neustraße 99' WHERE id = ?", [$c]);
        $f->db->exec("UPDATE companies SET address = 'Umzugsweg 7' WHERE id = ?", [$f->companyA]);
        try {
            $t = PdfFixture::text($f->renderer($f->companyA)->render($id));
        } finally {
            $f->db->exec("UPDATE companies SET address = ? WHERE id = ?", ["Musterweg 1\n10115 Berlin", $f->companyA]);
        }
        $this->assertStringContainsString('Alter Name GmbH', $t);
        $this->assertStringContainsString('Altstraße 1', $t);
        $this->assertStringContainsString('Musterweg 1', $t);
        $this->assertStringNotContainsString('Neuer Name', $t);
        $this->assertStringNotContainsString('Neustraße', $t);
        $this->assertStringNotContainsString('Umzugsweg', $t);
    }
}
