<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\Request;
use App\Core\Response;

final class SettingsTest extends HttpTestCase
{
    private string $storage;
    private int $company;
    /** @var array{id: int, secret: string} */
    private array $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/faktura-storage-' . bin2hex(random_bytes(6));
        mkdir($this->storage);
        $this->app = $this->makeApp(['storage_path' => $this->storage, 'trust_upload_paths' => true]);
        $this->company = $this->createCompany('Muster GmbH');
        $this->owner = $this->createUser('owner@example.com');
        $this->addMember($this->owner['id'], $this->company, 'owner');
    }

    protected function tearDown(): void
    {
        $this->rm($this->storage);
    }

    private function rm(string $dir): void
    {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) {
                continue;
            }
            is_dir($f) ? $this->rm($f) : unlink($f);
        }
        rmdir($dir);
    }

    /** @param array<string, string> $query */
    private function getWith(string $path, array $query): Response
    {
        return $this->app->handle(new Request('GET', $path, query: $query, server: ['REMOTE_ADDR' => self::IP]));
    }

    private function loginOwner(): void
    {
        $this->loginFully($this->owner['secret'], 'owner@example.com');
    }

    /** @return array{id: int, secret: string} a second user with the given role in the main company */
    private function loginMember(string $role): array
    {
        $u = $this->createUser('member@example.com');
        $this->addMember($u['id'], $this->company, $role);
        $this->loginFully($u['secret'], 'member@example.com');
        return $u;
    }

    private function companyRow(?int $id = null): array
    {
        return $this->db->one('SELECT * FROM companies WHERE id = ?', [$id ?? $this->company]);
    }

    /** @return array<string, string> */
    private function companyForm(array $over = []): array
    {
        return $over + [
            'name' => 'Muster Service GmbH', 'address' => 'Musterweg 1', 'tax_number' => '12/345/67890', 'vat_id' => 'DE987654321',
            'iban' => 'DE89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX', 'bank' => 'Commerzbank', 'default_lang' => 'de',
            'default_currency' => 'EUR', 'default_payment_days' => '14', 'intro_text' => 'Intro', 'outro_text' => 'Outro', 'footer_text' => 'Footer',
        ];
    }

    private function png(int $w = 40, int $h = 30): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    private function jpeg(int $w = 40, int $h = 30): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 30, 200));
        ob_start();
        imagejpeg($im);
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $override fields of the $_FILES entry to replace */
    private function upload(string $bytes, string $name = 'logo.png', bool $csrf = true, array $override = []): Response
    {
        $tmp = tempnam($this->storage, 'up');
        file_put_contents($tmp, $bytes);
        $file = $override + ['name' => $name, 'type' => 'image/png', 'tmp_name' => $tmp, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK];
        $post = $csrf ? ['_csrf' => $this->app->csrf()->token()] : [];
        return $this->app->handle(new Request('POST', '/settings/company/logo', post: $post, server: ['REMOTE_ADDR' => self::IP], files: ['logo' => $file]));
    }

    private function logoFiles(): array
    {
        return glob($this->storage . '/logos/*') ?: [];
    }

    // ---- access ----

    public function test_unauthenticated_requests_go_to_login(): void
    {
        foreach (['/settings', '/settings/company', '/settings/dunning', '/settings/mail', '/settings/security', '/company/logo'] as $p) {
            $this->assertRedirect('/login', $this->get($p));
        }
        foreach (['/settings/company', '/settings/dunning', '/settings/mail', '/settings/company/logo', '/settings/company/logo/remove', '/settings/security/backup-codes', '/settings/security/password'] as $p) {
            $this->assertRedirect('/login', $this->post($p));
        }
    }

    public function test_settings_index_redirects_per_role_and_nav_item_is_enabled(): void
    {
        $this->loginOwner();
        $this->assertRedirect('/settings/company', $this->get('/settings'));
        $this->assertStringContainsString('href="/settings"', $this->get('/')->body);
        $this->post('/logout');
        $this->loginMember('user');
        $this->assertRedirect('/settings/security', $this->get('/settings'));
    }

    public function test_role_user_gets_403_on_company_dunning_mail_but_can_use_security(): void
    {
        $this->loginMember('user');
        $before = $this->companyRow();
        foreach (['/settings/company', '/settings/dunning', '/settings/mail'] as $p) {
            $this->assertSame(403, $this->get($p)->status, "GET $p");
            $this->assertSame(403, $this->post($p, ['name' => 'Hacked', 'dunning_d0' => '1', 'smtp_host' => 'evil.example.com'])->status, "POST $p");
        }
        $this->assertSame(403, $this->upload($this->png())->status);
        $this->assertSame(403, $this->post('/settings/company/logo/remove')->status);
        $this->assertSame($before, $this->companyRow());
        $this->assertSame([], $this->logoFiles());
        $r = $this->get('/settings/security');
        $this->assertSame(200, $r->status);
        $this->assertStringNotContainsString('href="/settings/company"', $r->body, 'owner tabs are hidden from role user');
    }

    public function test_pages_render_tabs_and_forms_for_the_owner(): void
    {
        $this->loginOwner();
        $r = $this->get('/settings/company');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('tabs-pill', $r->body);
        foreach (['/settings/company', '/settings/dunning', '/settings/mail', '/settings/security'] as $p) {
            $this->assertStringContainsString('href="' . $p . '"', $r->body);
        }
        $this->assertStringContainsString('aria-current="page"', $r->body);
        $this->assertStringContainsString('enctype="multipart/form-data"', $r->body);
        $this->assertStringContainsString('PNG/JPEG', $r->body);
        $this->assertStringNotContainsString('SVG', $r->body);
        foreach (['dunning', 'mail', 'security'] as $tab) {
            $this->assertSame(200, $this->get('/settings/' . $tab)->status);
        }
    }

    public function test_lang_files_have_no_svg_and_all_three_say_png_jpeg(): void
    {
        foreach (['de', 'pl', 'en'] as $l) {
            $texts = require __DIR__ . '/../../lang/' . $l . '.php';
            $this->assertStringContainsString('PNG/JPEG', $texts['settings.logo_upload'], $l);
            $this->assertStringNotContainsString('SVG', $texts['settings.logo_upload'], $l);
        }
    }

    public function test_post_without_csrf_is_419(): void
    {
        $this->loginOwner();
        foreach (['/settings/company', '/settings/dunning', '/settings/mail', '/settings/company/logo/remove', '/settings/security/backup-codes', '/settings/security/password'] as $p) {
            $this->assertSame(419, $this->post($p, ['name' => 'X'], csrf: false)->status, $p);
        }
        $this->assertSame(419, $this->upload($this->png(), csrf: false)->status);
        $this->assertSame('Muster GmbH', $this->companyRow()['name']);
        $this->assertSame([], $this->logoFiles());
    }

    // ---- company tab ----

    public function test_company_save_and_prefill(): void
    {
        $this->loginOwner();
        $r = $this->post('/settings/company', $this->companyForm(['vat_id' => 'de 987 654 321', 'iban' => 'de89 3704 0044 0532 0130 00']));
        $this->assertRedirect('/settings/company?saved=1', $r);
        $row = $this->companyRow();
        $this->assertSame('Muster Service GmbH', $row['name']);
        $this->assertSame('DE987654321', $row['vat_id']);
        $this->assertSame('DE89370400440532013000', $row['iban']);
        $page = $this->getWith('/settings/company', ['saved' => '1'])->body;
        $this->assertStringContainsString('value="Muster Service GmbH"', $page);
        $this->assertStringContainsString('role="status"', $page);
    }

    public function test_company_validation_error_rerenders_with_input_and_422(): void
    {
        $this->loginOwner();
        $r = $this->post('/settings/company', $this->companyForm(['name' => 'Ungespeichert AG', 'iban' => 'DE00 1234']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Ungespeichert AG', $r->body);
        $this->assertStringContainsString('role="alert"', $r->body);
        $this->assertSame('Muster GmbH', $this->companyRow()['name']);
    }

    public function test_company_data_is_escaped_in_every_field(): void
    {
        $this->loginOwner();
        $xss = '"><script>alert(1)</script>';
        $this->post('/settings/company', $this->companyForm([
            'name' => 'N' . $xss, 'address' => 'A' . $xss, 'tax_number' => 'T' . $xss, 'bank' => 'B' . $xss,
            'intro_text' => 'I' . $xss, 'outro_text' => 'O' . $xss, 'footer_text' => 'F' . $xss,
        ]));
        $page = $this->get('/settings/company')->body;
        $this->assertStringNotContainsString('<script>alert(1)', $page);
        $this->assertGreaterThanOrEqual(7, substr_count($page, '&lt;script&gt;alert(1)&lt;/script&gt;'));
        // Error re-render echoes the typed values escaped too.
        $bad = $this->post('/settings/company', $this->companyForm(['iban' => '<img src=x onerror=alert(1)>', 'bic' => '<blink>', 'vat_id' => '<i>', 'default_payment_days' => '<u>']));
        $this->assertSame(422, $bad->status);
        $this->assertStringNotContainsString('<img src=x', $bad->body);
        $this->assertStringNotContainsString('<blink>', $bad->body);
        // Company name in the shell, dunning/mail pages.
        $this->post('/settings/mail', ['smtp_host' => '<script>x</script>', 'smtp_port' => '', 'smtp_user' => '<s>', 'smtp_pass' => '', 'smtp_from_name' => '<s>', 'smtp_from_addr' => '<s>']);
        $this->assertStringNotContainsString('<script>x', $this->get('/settings/mail')->body);
    }

    public function test_company_post_ignores_id_and_company_id_and_foreign_rows(): void
    {
        $other = $this->createCompany('Fremde AG');
        $this->loginOwner();
        $r = $this->post('/settings/company', $this->companyForm(['id' => (string) $other, 'company_id' => (string) $other, 'plan' => 'hack', 'access_status' => 'active', 'name' => 'Nur ich']));
        $this->assertRedirect('/settings/company?saved=1', $r);
        $this->assertSame('Fremde AG', $this->companyRow($other)['name']);
        $this->assertSame('Nur ich', $this->companyRow()['name']);
        $this->assertSame('free', $this->companyRow()['plan']);
        // Query string cannot select another company either.
        $this->assertStringNotContainsString('Fremde AG', $this->getWith('/settings/company', ['id' => (string) $other, 'company_id' => (string) $other])->body);
        $this->post('/settings/dunning', $this->dunningForm(['id' => (string) $other, 'dunning_d0' => '3']));
        $this->assertSame(7, (int) $this->companyRow($other)['dunning_d0']);
    }

    public function test_company_save_writes_audit_row(): void
    {
        $this->loginOwner();
        $this->post('/settings/company', $this->companyForm(['name' => 'Audit AG']));
        $row = $this->db->one("SELECT * FROM audit_log WHERE action = 'settings.company_save'");
        $this->assertSame($this->company, (int) $row['company_id']);
        $this->assertSame($this->owner['id'], (int) $row['user_id']);
        $this->assertStringNotContainsString('Audit AG', (string) $row['detail']);
        $this->assertContains('name', json_decode((string) $row['detail'], true)['fields']);
    }

    // ---- dunning tab ----

    /** @return array<string, string> */
    private function dunningForm(array $over = []): array
    {
        return $over + [
            'dunning_d0' => '7', 'dunning_d1' => '21', 'dunning_d2' => '35', 'dunning_d3' => '49',
            'dunning_base_rate' => '', 'dunning_b2b' => '9', 'dunning_b2c' => '5', 'dunning_flat' => '40', 'dunning_fee' => '',
        ];
    }

    public function test_dunning_page_shows_defaults_hints_and_empty_base_rate(): void
    {
        $this->loginOwner();
        $page = $this->get('/settings/dunning')->body;
        $this->assertStringContainsString('value="7"', $page);
        $this->assertStringContainsString('value="49"', $page);
        $this->assertStringContainsString('name="dunning_base_rate" value=""', $page);
        $this->assertStringContainsString('Bundesbank', $page);
        $this->assertStringContainsString('standardmäßig aus', $page);
        $this->assertStringContainsString('value="40,00"', $page);
    }

    public function test_dunning_save_german_and_english_decimals(): void
    {
        $this->loginOwner();
        $this->assertRedirect('/settings/dunning?saved=1', $this->post('/settings/dunning', $this->dunningForm(['dunning_base_rate' => '1,27', 'dunning_b2b' => '8,5', 'dunning_flat' => '45,50', 'dunning_fee' => '5'])));
        $row = $this->companyRow();
        $this->assertSame([127, 850, 4550, 500], [(int) $row['dunning_base_rate_bp'], (int) $row['dunning_b2b_surcharge_bp'], (int) $row['dunning_flat_minor'], (int) $row['dunning_fee_minor']]);
        $this->assertStringContainsString('value="1,27"', $this->get('/settings/dunning')->body);

        $this->post('/lang', ['lang' => 'en']);
        $this->assertRedirect('/settings/dunning?saved=1', $this->post('/settings/dunning', $this->dunningForm(['dunning_base_rate' => '2.25', 'dunning_flat' => '41.00'])));
        $this->assertSame(225, (int) $this->companyRow()['dunning_base_rate_bp']);
        $this->assertSame(4100, (int) $this->companyRow()['dunning_flat_minor']);
        $this->assertStringContainsString('value="2.25"', $this->get('/settings/dunning')->body);
        $this->assertRedirect('/settings/dunning?saved=1', $this->post('/settings/dunning', $this->dunningForm(['dunning_base_rate' => ''])));
        $this->assertNull($this->companyRow()['dunning_base_rate_bp']);
    }

    public function test_dunning_errors_show_per_field_and_save_nothing(): void
    {
        $this->loginOwner();
        $r = $this->post('/settings/dunning', $this->dunningForm(['dunning_d1' => '5', 'dunning_base_rate' => '31']));
        $this->assertSame(422, $r->status);
        $this->assertSame(2, substr_count($r->body, 'class="warn" role="alert"') - substr_count($r->body, 'class="warn" role="alert">' . 'Bitte prüfe'));
        $this->assertStringContainsString('value="5"', $r->body);
        $this->assertSame(21, (int) $this->companyRow()['dunning_d1']);
        $this->assertSame(422, $this->post('/settings/dunning', $this->dunningForm(['dunning_base_rate' => '1,234']))->status);
        $this->assertSame(422, $this->post('/settings/dunning', $this->dunningForm(['dunning_flat' => '1001']))->status);
    }

    // ---- mail tab ----

    /** @return array<string, string> */
    private function mailForm(array $over = []): array
    {
        return $over + [
            'smtp_host' => 'smtp.example.com', 'smtp_port' => '465', 'smtp_user' => 'rechnung@example.com',
            'smtp_pass' => 'S3cret-Passw0rd!', 'smtp_from_name' => 'Muster', 'smtp_from_addr' => 'rechnung@example.com',
        ];
    }

    public function test_mail_tab_status_chip_and_hint(): void
    {
        $this->loginOwner();
        $page = $this->get('/settings/mail')->body;
        $this->assertStringContainsString('chip-draft', $page);
        $this->assertStringNotContainsString('chip-ok', $page);
        $this->assertStringContainsString('STARTTLS', $page);
        $this->assertStringContainsString('465', $page);
        $this->assertStringNotContainsString('>Testmail senden</button>', $page, 'the test-mail button needs a complete configuration (Task 18)');
        $this->post('/settings/mail', $this->mailForm());
        $page = $this->get('/settings/mail')->body;
        $this->assertStringContainsString('>Testmail senden</button>', $page);
        $this->assertStringContainsString('chip-ok', $page);
        $this->assertStringContainsString('(gespeichert)', $page);
        $this->post('/settings/mail', $this->mailForm(['smtp_host' => '', 'smtp_pass' => '']));
        $this->assertStringContainsString('chip-draft', $this->get('/settings/mail')->body);
    }

    public function test_smtp_password_never_in_html_or_audit_and_kept_when_blank(): void
    {
        $this->loginOwner();
        $saved = $this->post('/settings/mail', $this->mailForm());
        $this->assertRedirect('/settings/mail?saved=1', $saved);
        $enc = (string) $this->companyRow()['smtp_pass_enc'];
        $this->assertNotSame('', $enc);
        $this->assertStringNotContainsString('S3cret', $enc);
        $this->assertSame('S3cret-Passw0rd!', $this->app->crypto()->decrypt($enc));
        $page = $this->get('/settings/mail')->body;
        $this->assertStringNotContainsString('S3cret', $page);
        $this->assertStringNotContainsString($enc, $page);
        $this->assertStringContainsString('type="password"', $page);
        // Validation error re-render: neither the typed nor the stored password is echoed.
        $err = $this->post('/settings/mail', $this->mailForm(['smtp_port' => '99999', 'smtp_pass' => 'Typed-Pa55word!']));
        $this->assertSame(422, $err->status);
        $this->assertStringNotContainsString('Typed-Pa55word', $err->body);
        $this->assertStringNotContainsString('S3cret', $err->body);
        $this->assertStringNotContainsString($enc, $err->body);
        $this->assertSame($enc, (string) $this->companyRow()['smtp_pass_enc']);
        // Blank keeps the old one.
        $this->post('/settings/mail', $this->mailForm(['smtp_pass' => '', 'smtp_host' => 'mail.example.org']));
        $this->assertSame($enc, (string) $this->companyRow()['smtp_pass_enc']);
        foreach ($this->db->all('SELECT detail FROM audit_log') as $a) {
            $this->assertStringNotContainsString('S3cret', (string) $a['detail']);
            $this->assertStringNotContainsString('Typed-Pa55word', (string) $a['detail']);
            $this->assertStringNotContainsString($enc, (string) $a['detail']);
        }
    }

    // ---- logo ----

    public function test_logo_accepts_png_and_jpeg_reencodes_and_serves_them(): void
    {
        $this->loginOwner();
        $r = $this->upload($this->png(60, 20));
        $this->assertRedirect('/settings/company?saved=1', $r);
        $this->assertSame('logos/company-' . $this->company . '.png', $this->companyRow()['logo_path']);
        $file = $this->storage . '/logos/company-' . $this->company . '.png';
        $this->assertFileExists($file);
        $this->assertSame(IMAGETYPE_PNG, getimagesize($file)[2]);
        $served = $this->get('/company/logo');
        $this->assertSame(200, $served->status);
        $this->assertSame('image/png', $served->headers['Content-Type']);
        $this->assertSame('nosniff', $served->headers['X-Content-Type-Options']);
        $this->assertStringContainsString('max-age', $served->headers['Cache-Control']);
        $this->assertSame(file_get_contents($file), $served->body);
        $this->assertStringContainsString('src="/company/logo', $this->get('/settings/company')->body);

        // JPEG replaces the PNG (one logo per company, no stale file).
        $this->assertRedirect('/settings/company?saved=1', $this->upload($this->jpeg(), 'x.jpg', override: ['type' => 'image/jpeg']));
        $this->assertSame('logos/company-' . $this->company . '.jpg', $this->companyRow()['logo_path']);
        $this->assertSame([$this->storage . '/logos/company-' . $this->company . '.jpg'], $this->logoFiles());
        $this->assertSame('image/jpeg', $this->get('/company/logo')->headers['Content-Type']);
        $this->assertSame(2, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action = 'settings.logo_save'")['c']);
    }

    public function test_logo_rejects_non_image_and_oversize(): void
    {
        $this->loginOwner();
        $big = imagecreatetruecolor(2500, 10);
        ob_start();
        imagepng($big);
        $wide = (string) ob_get_clean();
        $cases = [
            'text renamed png' => ['just some text', 'logo.png'],
            'php with png signature' => ["\x89PNG\r\n\x1a\n<?php system(\$_GET['c']); ?>", 'logo.png'],
            'php behind gif header' => ["GIF89a<?php system(\$_GET['c']); ?>", 'logo.png'],
            'gif image' => [base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 'logo.png'],
            'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg'],
            'svg named png' => ['<svg xmlns="http://www.w3.org/2000/svg" width="5" height="5"/>', 'logo.png'],
            '1.2 MB image' => [$this->png() . str_repeat("\0", 1_200_000), 'logo.png'],
            '2500 px wide image' => [$wide, 'logo.png'],
            'empty file' => ['', 'logo.png'],
        ];
        foreach ($cases as $label => [$bytes, $name]) {
            $r = $this->upload($bytes, $name);
            $this->assertSame(422, $r->status, $label);
            $this->assertStringContainsString('role="alert"', $r->body, $label);
            $this->assertNull($this->companyRow()['logo_path'], $label);
            $this->assertSame([], $this->logoFiles(), $label);
        }
        $tall = imagecreatetruecolor(10, 2001);
        ob_start();
        imagejpeg($tall);
        $this->assertSame(422, $this->upload((string) ob_get_clean(), 'l.jpg')->status);
        // Exactly 2000x2000 is fine.
        $edge = imagecreatetruecolor(2000, 2000);
        ob_start();
        imagepng($edge);
        $this->assertRedirect('/settings/company?saved=1', $this->upload((string) ob_get_clean()));
    }

    public function test_logo_upload_error_codes_and_missing_file(): void
    {
        $this->loginOwner();
        $this->assertSame(422, $this->upload($this->png(), override: ['error' => UPLOAD_ERR_INI_SIZE])->status);
        $this->assertSame(422, $this->upload($this->png(), override: ['error' => UPLOAD_ERR_NO_FILE])->status);
        $noFile = $this->app->handle(new Request('POST', '/settings/company/logo', post: ['_csrf' => $this->app->csrf()->token()], server: ['REMOTE_ADDR' => self::IP]));
        $this->assertSame(422, $noFile->status);
        $this->assertNull($this->companyRow()['logo_path']);
    }

    public function test_logo_only_accepts_real_uploaded_files_in_production_mode(): void
    {
        $this->loginOwner();
        $this->app = $this->makeApp(['storage_path' => $this->storage]); // trust_upload_paths off
        // A path that merely exists on disk (not via PHP's upload handling) is refused.
        $r = $this->upload($this->png());
        $this->assertSame(422, $r->status);
        $this->assertNull($this->companyRow()['logo_path']);
        // A tmp_name pointing at some server file never gets read.
        $r = $this->upload($this->png(), override: ['tmp_name' => '/etc/passwd']);
        $this->assertSame(422, $r->status);
    }

    public function test_logo_strips_exif_comment_and_trailing_payload_from_jpeg(): void
    {
        $this->loginOwner();
        $jpeg = $this->jpeg();
        $exif = "Exif\0\0SECRETPAYLOAD-GPS-1234";
        $app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
        $com = "\xFF\xFE" . pack('n', 2 + 14) . 'SECRETCOMMENT!';
        $tainted = substr($jpeg, 0, 2) . $app1 . $com . substr($jpeg, 2) . '<?php echo "SECRETTRAIL"; ?>';
        $this->assertNotFalse(getimagesizefromstring($tainted));
        $this->assertStringContainsString('SECRETPAYLOAD', $tainted);
        $this->assertRedirect('/settings/company?saved=1', $this->upload($tainted, 'photo.jpg', override: ['type' => 'image/jpeg']));
        $stored = (string) file_get_contents($this->storage . '/logos/company-' . $this->company . '.jpg');
        foreach (['SECRETPAYLOAD', 'SECRETCOMMENT', 'SECRETTRAIL', '<?php', 'Exif'] as $needle) {
            $this->assertStringNotContainsString($needle, $stored, $needle);
        }
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($stored)[2]);
        // Same for a PNG with a trailing payload after IEND and a text chunk.
        $png = $this->png();
        $text = 'tEXtComment' . "\0" . 'SECRETPNGTEXT';
        $chunk = pack('N', strlen($text) - 4) . $text . pack('N', crc32($text));
        $taintedPng = substr($png, 0, 33) . $chunk . substr($png, 33) . '<?php SECRETPNGTRAIL ?>';
        $this->assertRedirect('/settings/company?saved=1', $this->upload($taintedPng));
        $stored = (string) file_get_contents($this->storage . '/logos/company-' . $this->company . '.png');
        $this->assertStringNotContainsString('SECRETPNG', $stored);
        $this->assertStringNotContainsString('<?php', $stored);
    }

    public function test_logo_name_is_never_taken_from_the_client(): void
    {
        $this->loginOwner();
        $this->upload($this->png(), '../../public/evil.php');
        $this->assertSame([$this->storage . '/logos/company-' . $this->company . '.png'], $this->logoFiles());
        $this->assertFileDoesNotExist($this->storage . '/../../public/evil.php');
    }

    public function test_logo_route_is_scoped_to_the_context_company(): void
    {
        $this->loginOwner();
        $this->assertSame(404, $this->get('/company/logo')->status, 'no logo yet');
        $this->upload($this->png(10, 10));
        $this->post('/logout');

        // Company B has no logo: user B gets 404, never A's file.
        $b = $this->createCompany('B GmbH');
        $ub = $this->createUser('b@example.com');
        $this->addMember($ub['id'], $b, 'owner');
        $this->loginFully($ub['secret'], 'b@example.com');
        $this->assertSame(404, $this->getWith('/company/logo', ['company_id' => (string) $this->company, 'id' => (string) $this->company])->status);
        // Even a forged path pointing at A's file in B's row is refused.
        $this->db->exec('UPDATE companies SET logo_path = ? WHERE id = ?', ['logos/company-' . $this->company . '.png', $b]);
        $this->assertSame(404, $this->get('/company/logo')->status);
        // B uploading its own logo only touches B's files.
        $this->db->exec('UPDATE companies SET logo_path = NULL WHERE id = ?', [$b]);
        $this->upload($this->jpeg(), 'b.jpg');
        $this->assertSame('image/jpeg', $this->get('/company/logo')->headers['Content-Type']);
        $this->assertFileExists($this->storage . '/logos/company-' . $this->company . '.png');
        $this->assertNotNull($this->companyRow()['logo_path']);
    }

    public function test_logo_route_refuses_odd_stored_paths(): void
    {
        $this->loginOwner();
        foreach (['../../etc/passwd', '/etc/passwd', 'logos/../../x.png', 'logos/company-999.png', 'logos/company-' . $this->company . '.svg'] as $bad) {
            $this->db->exec('UPDATE companies SET logo_path = ? WHERE id = ?', [$bad, $this->company]);
            $this->assertSame(404, $this->get('/company/logo')->status, $bad);
        }
        // Path looks right but the file is missing.
        $this->db->exec('UPDATE companies SET logo_path = ? WHERE id = ?', ['logos/company-' . $this->company . '.png', $this->company]);
        $this->assertSame(404, $this->get('/company/logo')->status);
    }

    public function test_remove_logo(): void
    {
        $this->loginOwner();
        $this->upload($this->png());
        $this->assertRedirect('/settings/company?saved=1', $this->post('/settings/company/logo/remove'));
        $this->assertNull($this->companyRow()['logo_path']);
        $this->assertSame([], $this->logoFiles());
        $this->assertSame(404, $this->get('/company/logo')->status);
        $this->assertRedirect('/settings/company?saved=1', $this->post('/settings/company/logo/remove'), 'removing nothing is harmless');
    }

    // ---- security tab ----

    private function codes(Response $r): array
    {
        preg_match_all('/<code>([A-Z2-9]{4}-[A-Z2-9]{4})<\/code>/', $r->body, $m);
        return $m[1];
    }

    public function test_security_page_shows_status_and_forms(): void
    {
        $this->loginOwner();
        $r = $this->get('/settings/security');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('chip-ok', $r->body);
        $this->assertStringContainsString('action="/settings/security/backup-codes"', $r->body);
        $this->assertStringContainsString('action="/settings/security/password"', $r->body);
        $this->assertSame([], $this->codes($r));
    }

    public function test_regenerate_backup_codes_requires_current_password(): void
    {
        $this->loginOwner();
        $old = $this->app->backupCodes()->generate($this->owner['id']);
        foreach (['', 'wrong password here'] as $bad) {
            $r = $this->post('/settings/security/backup-codes', ['current_password' => $bad]);
            $this->assertSame(422, $r->status);
            $this->assertSame([], $this->codes($r));
        }
        $this->assertSame(8, (int) $this->db->one('SELECT COUNT(*) c FROM backup_codes WHERE user_id = ? AND used_at IS NULL', [$this->owner['id']])['c']);

        $r = $this->post('/settings/security/backup-codes', ['current_password' => self::PASSWORD]);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('no-store', $r->headers['Cache-Control']);
        $new = $this->codes($r);
        $this->assertCount(8, array_unique($new));
        $this->assertSame([], array_intersect($new, $old));
        $this->assertFalse($this->app->backupCodes()->consume($this->owner['id'], $old[0]), 'old codes stop working');
        $this->assertTrue($this->app->backupCodes()->consume($this->owner['id'], $new[0]));
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action = 'security.backup_codes_regenerate'")['c']);
        // Shown once: a reload of the page does not contain them, nor does the DB hold them in clear.
        $this->assertSame([], $this->codes($this->get('/settings/security')));
        $this->assertStringNotContainsString($new[1], json_encode($this->db->all('SELECT * FROM backup_codes')));
        foreach ($this->db->all('SELECT detail FROM audit_log') as $a) {
            $this->assertStringNotContainsString($new[1], (string) $a['detail']);
        }
    }

    public function test_wrong_passwords_are_rate_limited_per_user_across_both_forms(): void
    {
        $this->loginOwner();
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(422, $this->post('/settings/security/backup-codes', ['current_password' => 'nope nope nope'])->status);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame(422, $this->post('/settings/security/password', ['current_password' => 'nope nope nope', 'new_password' => 'brand new passphrase 99', 'new_password_confirm' => 'brand new passphrase 99'])->status);
        }
        // Locked now: even the correct password is refused for both actions.
        $this->assertSame(429, $this->post('/settings/security/backup-codes', ['current_password' => self::PASSWORD])->status);
        $this->assertSame(429, $this->post('/settings/security/password', ['current_password' => self::PASSWORD, 'new_password' => 'brand new passphrase 99', 'new_password_confirm' => 'brand new passphrase 99'])->status);
        $hash = $this->db->one('SELECT password_hash h FROM users WHERE id = ?', [$this->owner['id']])['h'];
        $this->assertTrue(password_verify(self::PASSWORD, $hash));
        // Window passes: works again.
        $this->now += 901;
        $this->app = $this->makeApp(['storage_path' => $this->storage, 'trust_upload_paths' => true]);
        $this->assertSame(200, $this->post('/settings/security/backup-codes', ['current_password' => self::PASSWORD])->status);
    }

    public function test_rate_limit_is_per_user(): void
    {
        $this->loginOwner();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/settings/security/backup-codes', ['current_password' => 'nope nope nope']);
        }
        $this->assertSame(429, $this->post('/settings/security/backup-codes', ['current_password' => self::PASSWORD])->status);
        $this->post('/logout');
        $u = $this->loginMember('user');
        $this->assertSame(200, $this->post('/settings/security/backup-codes', ['current_password' => self::PASSWORD])->status);
        $this->assertNotSame($u['id'], $this->owner['id']);
    }

    /** @return array<string, string> */
    private function pwForm(array $over = []): array
    {
        return $over + ['current_password' => self::PASSWORD, 'new_password' => 'brand new passphrase 99', 'new_password_confirm' => 'brand new passphrase 99'];
    }

    public function test_password_change_matrix(): void
    {
        $this->loginOwner();
        $hash0 = $this->db->one('SELECT password_hash h FROM users WHERE id = ?', [$this->owner['id']])['h'];
        $cases = [
            'wrong current' => [['current_password' => 'not the password'], 'current_password'],
            'too short' => [['new_password' => 'short pw 11', 'new_password_confirm' => 'short pw 11'], 'new_password'],
            'same as old' => [['new_password' => self::PASSWORD, 'new_password_confirm' => self::PASSWORD], 'new_password'],
            'mismatch' => [['new_password_confirm' => 'something else entirely'], 'new_password_confirm'],
            'empty' => [['new_password' => '', 'new_password_confirm' => ''], 'new_password'],
        ];
        foreach ($cases as $label => [$over, $field]) {
            $r = $this->post('/settings/security/password', $this->pwForm($over));
            $this->assertSame(422, $r->status, $label);
            $this->assertStringContainsString('role="alert"', $r->body, $label);
            $this->assertStringNotContainsString('brand new passphrase', $r->body, $label);
            $this->assertSame($hash0, $this->db->one('SELECT password_hash h FROM users WHERE id = ?', [$this->owner['id']])['h'], $label);
            $this->assertStringContainsString('name="' . $field . '"', $r->body);
        }
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action = 'security.password_change'")['c']);
    }

    public function test_password_change_success_keeps_session_rotates_id_and_csrf(): void
    {
        $this->loginOwner();
        $oldCsrf = $this->app->csrf()->token();
        $regen = $this->store->regenerations();
        $r = $this->post('/settings/security/password', $this->pwForm());
        $this->assertRedirect('/settings/security?pw=1', $r);
        $hash = $this->db->one('SELECT password_hash h FROM users WHERE id = ?', [$this->owner['id']])['h'];
        $this->assertTrue(password_verify('brand new passphrase 99', $hash));
        $this->assertFalse(password_verify(self::PASSWORD, $hash));
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertGreaterThan($regen, $this->store->regenerations(), 'session id regenerated');
        $this->assertNotSame($oldCsrf, $this->app->csrf()->token(), 'CSRF rotated');
        // Still logged in.
        $page = $this->getWith('/settings/security', ['pw' => '1']);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('role="status"', $page->body);
        $audit = $this->db->one("SELECT * FROM audit_log WHERE action = 'security.password_change'");
        $this->assertSame($this->owner['id'], (int) $audit['user_id']);
        $this->assertStringNotContainsString('brand new', (string) $audit['detail']);
        $this->assertStringNotContainsString('argon2', (string) $audit['detail']);
        // The old CSRF token no longer works, the new login works with the new password.
        $this->assertSame(419, $this->app->handle(new Request('POST', '/settings/security/password', post: ['_csrf' => $oldCsrf] + $this->pwForm(), server: ['REMOTE_ADDR' => self::IP]))->status);
        $this->post('/logout');
        $this->assertRedirect('/login/2fa', $this->loginPassword('owner@example.com', 'brand new passphrase 99'));
    }

    public function test_password_change_success_clears_the_rate_limit_counter(): void
    {
        $this->loginOwner();
        for ($i = 0; $i < 4; $i++) {
            $this->post('/settings/security/password', $this->pwForm(['current_password' => 'wrong wrong wrong']));
        }
        $this->assertRedirect('/settings/security?pw=1', $this->post('/settings/security/password', $this->pwForm()));
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(422, $this->post('/settings/security/password', $this->pwForm(['current_password' => 'wrong wrong wrong']))->status);
        }
    }
}
