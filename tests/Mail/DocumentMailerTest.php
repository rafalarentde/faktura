<?php
declare(strict_types=1);

namespace Tests\Mail;

use App\Core\Crypto;
use App\Domain\DocumentNotFound;
use App\Domain\ValidationException;
use App\Http\DocumentPresenter;
use App\Mail\DocumentMailer;
use App\Mail\MailNotConfigured;
use App\Mail\MailSendFailed;
use App\Pdf\PdfNotAvailable;
use App\Pdf\PdfRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Pdf\PdfFixture;
use Tests\Support\FakeResolver;
use Tests\Support\FakeTransport;
use App\Mail\SmtpTargetPolicy;

/** Sending issued documents: access, validation, PDF attachment, log, audit, secrets, rate limit. */
final class DocumentMailerTest extends TestCase
{
    private const PASSWORD = 'S3cr3t-Passw0rd!';

    private PdfFixture $fx;
    private FakeTransport $transport;
    private int $now = PdfFixture::NOW;
    private int $customer;
    private int $doc;

    protected function setUp(): void
    {
        $this->fx = new PdfFixture();
        $this->transport = new FakeTransport();
        $this->customer = $this->fx->customer($this->fx->companyA, ['email' => 'buchhaltung@bauhaus.example']);
        $this->doc = $this->fx->issued($this->fx->companyA, $this->customer);
        $this->configureSmtp($this->fx->companyA);
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    private function crypto(): Crypto
    {
        return new Crypto(base64_encode(str_repeat('k', 32)));
    }

    private function configureSmtp(int $company, string $pass = self::PASSWORD): void
    {
        $this->fx->db->exec(
            'UPDATE companies SET smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass_enc = ?, smtp_from_name = ?, smtp_from_addr = ? WHERE id = ?',
            ['smtp.firma.example', 587, 'rechnung@firma.example', $this->crypto()->encrypt($pass), 'Muster Service GmbH', 'rechnung@firma.example', $company]
        );
    }

    private function mailer(?int $company = null): DocumentMailer
    {
        $company ??= $this->fx->companyA;
        $ctx = $this->fx->ctx($company);
        $repo = $this->fx->repo($company);
        $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(PdfFixture::ROOT . '/templates'), ['autoescape' => 'html', 'strict_variables' => true]);
        $renderer = new PdfRenderer($twig, new DocumentPresenter($this->fx->translator(), $repo), $repo, new \App\Storage\LogoStore($this->fx->storage),
            $ctx, PdfFixture::ROOT . '/resources', PdfFixture::cacheDir());
        return new DocumentMailer(
            $this->fx->db, $ctx, $repo, new DocumentPresenter($this->fx->translator(), $repo), $renderer, $this->transport,
            $this->crypto(), $this->fx->translator(), fn (): int => $this->now, PdfFixture::ROOT . '/templates/mail',
            new SmtpTargetPolicy(FakeResolver::publicHosts())
        );
    }

    private function rows(string $table): int
    {
        return (int) $this->fx->db->one("SELECT COUNT(*) AS n FROM $table")['n'];
    }

    private function dump(): string
    {
        $out = '';
        foreach (['email_log', 'audit_log', 'login_attempts'] as $t) {
            $out .= json_encode($this->fx->db->all("SELECT * FROM $t"), JSON_UNESCAPED_UNICODE);
        }
        return $out;
    }

    /** @return array{to: string, subject: string, body: string} */
    private function defaults(?int $doc = null): array
    {
        $p = $this->mailer()->preview($doc ?? $this->doc);
        return ['to' => $p['to'], 'subject' => $p['subject'], 'body' => $p['body']];
    }

    public function test_send_attaches_pdf_and_logs(): void
    {
        $m = $this->mailer();
        $m->send($this->doc, 'kunde@example.de', 'Rechnung RE-2026-001 von Muster Service GmbH', "Hallo,\nanbei die Rechnung.\n");
        $this->assertCount(1, $this->transport->sent);
        $call = $this->transport->sent[0];
        $this->assertSame('kunde@example.de', $call['to']);
        $this->assertSame('Rechnung RE-2026-001 von Muster Service GmbH', $call['subject']);
        $this->assertSame("Hallo,\nanbei die Rechnung.\n", $call['body']);
        $this->assertSame('rechnung@firma.example', $call['from']);
        $this->assertSame('Muster Service GmbH', $call['fromName']);
        $this->assertCount(1, $call['attachments']);
        $att = $call['attachments'][0];
        $this->assertSame('RE-2026-001.pdf', $att['name']);
        $this->assertSame('application/pdf', $att['mime']);
        $this->assertStringStartsWith('%PDF-', $att['content']);

        $rows = $this->fx->db->all('SELECT * FROM email_log');
        $this->assertCount(1, $rows);
        $this->assertSame('sent', $rows[0]['status']);
        $this->assertNull($rows[0]['error']);
        $this->assertSame('kunde@example.de', $rows[0]['to_email']);
        $this->assertSame('Rechnung RE-2026-001 von Muster Service GmbH', $rows[0]['subject']);
        $this->assertSame($this->fx->companyA, (int) $rows[0]['company_id']);
        $this->assertSame($this->doc, (int) $rows[0]['document_id']);
        $this->assertSame($this->fx->userId, (int) $rows[0]['sent_by']);
        $this->assertSame('2026-11-24 10:00:00', $rows[0]['created_at']);
        $this->assertArrayNotHasKey('body', $rows[0]);
    }

    public function test_audit_row_has_ids_and_status_only(): void
    {
        $this->mailer()->send($this->doc, 'kunde@example.de', 'Geheimer Betreff', 'Geheimer Text');
        $rows = $this->fx->db->all("SELECT * FROM audit_log WHERE action = 'document.email_send'");
        $this->assertCount(1, $rows);
        $this->assertSame($this->fx->companyA, (int) $rows[0]['company_id']);
        $this->assertSame($this->fx->userId, (int) $rows[0]['user_id']);
        $this->assertSame(['document_id' => $this->doc, 'status' => 'sent'], json_decode((string) $rows[0]['detail'], true));
        foreach (['kunde@example.de', 'Geheimer', 'example.de'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $rows[0]['detail']);
        }
    }

    public function test_failure_is_logged_and_throws_readable_error(): void
    {
        $this->transport->failWith = 'auth';
        try {
            $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', 'Text');
            $this->fail('expected MailSendFailed');
        } catch (MailSendFailed $e) {
            $this->assertSame('auth', $e->reason);
            $this->assertSame('mail.fail_auth', $e->translationKey());
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage() . $e->getTraceAsString());
        }
        $row = $this->fx->db->one('SELECT * FROM email_log');
        $this->assertSame('failed', $row['status']);
        $this->assertSame('auth', $row['error']);
        $audit = $this->fx->db->one("SELECT detail FROM audit_log WHERE action = 'document.email_send'");
        $this->assertSame(['document_id' => $this->doc, 'status' => 'failed', 'reason' => 'auth'], json_decode((string) $audit['detail'], true));
        $this->assertStringNotContainsString(self::PASSWORD, $this->dump());
        $this->assertStringNotContainsString('smtp_pass', $this->dump());
    }

    public function test_unexpected_transport_error_is_logged_as_other_without_its_text(): void
    {
        $this->transport->throw = new \RuntimeException('boom ' . self::PASSWORD);
        try {
            $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', 'Text');
            $this->fail('expected MailSendFailed');
        } catch (MailSendFailed $e) {
            $this->assertSame('other', $e->reason);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
        $this->assertSame('other', $this->fx->db->one('SELECT error FROM email_log')['error']);
        $this->assertStringNotContainsString(self::PASSWORD, $this->dump());
    }

    public function test_nothing_is_sent_on_preview(): void
    {
        $before = [$this->rows('email_log'), $this->rows('login_attempts'), $this->rows('audit_log')];
        $p = $this->mailer()->preview($this->doc);
        $this->assertTrue($p['configured']);
        $this->assertSame([], $this->transport->sent);
        $this->assertSame($before, [$this->rows('email_log'), $this->rows('login_attempts'), $this->rows('audit_log')]);
    }

    public function test_preview_defaults(): void
    {
        $p = $this->mailer()->preview($this->doc);
        $this->assertSame('buchhaltung@bauhaus.example', $p['to']);
        $this->assertSame('Rechnung RE-2026-001 von Muster Service GmbH', $p['subject']);
        $this->assertSame('RE-2026-001.pdf', $p['attachment_name']);
        $this->assertSame('de', $p['lang']);
        $this->assertStringContainsString('Bauhaus Logistik GmbH', $p['body']);
        $this->assertStringContainsString('RE-2026-001', $p['body']);
        $this->assertStringContainsString('1.', $p['body']);
        $this->assertStringContainsString('02.12.2026', $p['body'], 'due date');
        $this->assertStringContainsString('DE89370400440532013000', $p['body']);
        $this->assertStringContainsString('COBADEFFXXX', $p['body']);
        $this->assertStringContainsString('Muster Service GmbH', $p['body']);
        $this->assertStringNotContainsString('<', $p['body']);
        $this->assertStringNotContainsString('{{', $p['body']);
        $this->assertDoesNotMatchRegularExpression("/\n{3,}/", $p['body']);
    }

    public function test_german_invoice_body_layout(): void
    {
        $vm = (new DocumentPresenter($this->fx->translator(), $this->fx->repo($this->fx->companyA)))->issued($this->fx->repo($this->fx->companyA)->find($this->doc));
        $this->assertSame(
            "Guten Tag Bauhaus Logistik GmbH,\n\nanbei erhalten Sie unsere Rechnung RE-2026-001 über {$vm['gross']} als PDF.\n\n"
            . "Bitte überweisen Sie den Betrag bis zum 02.12.2026 ohne Abzug.\n\nIBAN: DE89370400440532013000\nBIC: COBADEFFXXX\n"
            . "Verwendungszweck: RE-2026-001\n\nBei Fragen melden Sie sich gern.\n\nMit freundlichen Grüßen\nMuster Service GmbH\n",
            $this->mailer()->preview($this->doc)['body']
        );
    }

    public function test_body_without_bank_details_has_no_payment_block(): void
    {
        $this->fx->db->exec("UPDATE documents SET seller_json = REPLACE(REPLACE(seller_json, 'DE89370400440532013000', ''), 'COBADEFFXXX', '') WHERE id = ?", [$this->doc]);
        $body = $this->mailer()->preview($this->doc)['body'];
        $this->assertStringNotContainsString('IBAN', $body);
        $this->assertStringNotContainsString('Verwendungszweck', $body);
        $this->assertStringContainsString('bis zum 02.12.2026', $body);
        $this->assertDoesNotMatchRegularExpression("/\n{3,}/", $body);
    }

    public function test_preview_amount_is_the_gross_in_document_format(): void
    {
        $vm = (new DocumentPresenter($this->fx->translator(), $this->fx->repo($this->fx->companyA)))->issued($this->fx->repo($this->fx->companyA)->find($this->doc));
        $this->assertStringContainsString($vm['gross'], $this->mailer()->preview($this->doc)['body']);
    }

    public function test_buyer_email_from_the_snapshot_is_validated_before_use(): void
    {
        foreach (["a@b.de\r\nBcc: x@y.de", 'a@b.de, c@d.de', '"x" <a@b.de>', '<script>@x.de', 'kein-mail'] as $odd) {
            $buyer = json_decode((string) $this->fx->db->one('SELECT buyer_json FROM documents WHERE id = ?', [$this->doc])['buyer_json'], true);
            $buyer['email'] = $odd;
            $this->fx->db->exec('UPDATE documents SET buyer_json = ? WHERE id = ?', [json_encode($buyer), $this->doc]);
            $this->assertSame('', $this->mailer()->preview($this->doc)['to'], $odd);
        }
    }

    public function test_preview_without_buyer_email_has_empty_recipient(): void
    {
        $c = $this->fx->customer($this->fx->companyA, ['name' => 'Ohne Mail GmbH']);
        $doc = $this->fx->issued($this->fx->companyA, $c);
        $this->assertSame('', $this->mailer()->preview($doc)['to']);
    }

    public function test_uses_company_smtp_credentials_decrypted(): void
    {
        $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', 'Text');
        $smtp = $this->transport->sent[0]['smtp'];
        $this->assertSame(['host' => 'smtp.firma.example', 'port' => 587, 'user' => 'rechnung@firma.example', 'pass' => self::PASSWORD, 'secure' => 'tls'], $smtp);
        $stored = (string) $this->fx->db->one('SELECT smtp_pass_enc FROM companies WHERE id = ?', [$this->fx->companyA])['smtp_pass_enc'];
        $this->assertNotSame(self::PASSWORD, $stored);
        $this->assertStringNotContainsString(self::PASSWORD, $stored);
    }

    public function test_port_465_uses_implicit_tls(): void
    {
        $this->fx->db->exec('UPDATE companies SET smtp_port = 465 WHERE id = ?', [$this->fx->companyA]);
        $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', 'Text');
        $this->assertSame('ssl', $this->transport->sent[0]['smtp']['secure']);
    }

    public function test_from_name_with_control_characters_is_cleaned(): void
    {
        $this->fx->db->exec('UPDATE companies SET smtp_from_name = ? WHERE id = ?', ["Firma\r\nBcc: evil@example.org", $this->fx->companyA]);
        $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', 'Text');
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $this->transport->sent[0]['fromName']);
    }

    /** @return array<string, array{string}> */
    public static function languages(): array
    {
        return [
            'de' => ['de'], 'pl' => ['pl'], 'en' => ['en'],
        ];
    }

    #[DataProvider('languages')]
    public function test_mail_subject_and_body_in_document_language(string $lang): void
    {
        $doc = $this->fx->issued($this->fx->companyA, $this->customer, ['lang' => $lang]);
        $number = (string) $this->fx->repo($this->fx->companyA)->find($doc)['number'];
        $p = $this->mailer()->preview($doc);
        $this->assertSame($lang, $p['lang']);
        $expect = [
            'de' => ['Rechnung ' . $number . ' von Muster Service GmbH', 'Guten Tag', 'Mit freundlichen Grüßen', '02.12.2026'],
            'pl' => ['Faktura ' . $number . ' od Muster Service GmbH', 'Dzień dobry', 'Z poważaniem', '02.12.2026'],
            'en' => ['Invoice ' . $number . ' from Muster Service GmbH', 'Hello', 'Kind regards', '02/12/2026'],
        ][$lang];
        $this->assertSame($expect[0], $p['subject']);
        $this->assertStringContainsString($expect[1], $p['body']);
        $this->assertStringContainsString($expect[2], $p['body']);
        $this->assertStringContainsString($number, $p['body']);
        $this->assertMatchesRegularExpression('/\d/', $p['body']);
    }

    public function test_ui_language_does_not_matter_only_the_document_language(): void
    {
        $doc = $this->fx->issued($this->fx->companyA, $this->customer, ['lang' => 'pl']);
        $this->assertStringStartsWith('Faktura ', $this->mailer()->preview($doc)['subject']);
    }

    public function test_unicode_survives_unchanged(): void
    {
        $subject = 'Faktura – zażółć gęślą jaźń / Rechnung für Müller & Söhne ß';
        $body = "Zażółć gęślą jaźń.\nGrüße aus Köln: äöüß ÄÖÜ\n";
        $this->mailer()->send($this->doc, 'kunde@example.de', $subject, $body);
        $this->assertSame($subject, $this->transport->sent[0]['subject']);
        $this->assertSame($body, $this->transport->sent[0]['body']);
        $this->assertSame($subject, $this->fx->db->one('SELECT subject FROM email_log')['subject']);
    }

    public function test_textarea_line_breaks_are_normalised_to_lf(): void
    {
        $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', "Zeile 1\r\nZeile 2\rZeile 3");
        $this->assertSame("Zeile 1\nZeile 2\nZeile 3", $this->transport->sent[0]['body']);
    }

    public function test_mail_uses_snapshots_not_live_rows(): void
    {
        $this->fx->db->exec("UPDATE customers SET name = 'Umbenannt AG', email = 'neu@example.org' WHERE id = ?", [$this->customer]);
        $this->fx->db->exec("UPDATE companies SET name = 'Neue Firma AG', iban = 'DE02120300000000202051' WHERE id = ?", [$this->fx->companyA]);
        $p = $this->mailer()->preview($this->doc);
        $this->assertStringContainsString('Bauhaus Logistik GmbH', $p['body']);
        $this->assertStringNotContainsString('Umbenannt', $p['body']);
        $this->assertStringContainsString('DE89370400440532013000', $p['body']);
        $this->assertStringNotContainsString('DE02120300000000202051', $p['body']);
        $this->assertStringNotContainsString('Neue Firma', $p['body'] . $p['subject']);
        $this->assertStringContainsString('Muster Service GmbH', $p['subject']);
        $this->assertSame('buchhaltung@bauhaus.example', $p['to']);
    }

    public function test_credit_note_mail(): void
    {
        $cn = $this->fx->creditNote($this->fx->companyA, $this->doc);
        $number = (string) $this->fx->repo($this->fx->companyA)->find($cn)['number'];
        $p = $this->mailer()->preview($cn);
        $this->assertSame('Rechnungskorrektur ' . $number . ' von Muster Service GmbH', $p['subject']);
        $this->assertStringContainsString($number, $p['body']);
        $this->assertStringContainsString('RE-2026-001', $p['body'], 'names the corrected invoice');
        $this->assertStringNotContainsString('DE89370400440532013000', $p['body'], 'no payment hint on a credit note');
        $this->assertSame($number . '.pdf', $p['attachment_name']);
        $this->mailer()->send($cn, 'kunde@example.de', $p['subject'], $p['body']);
        $this->assertSame($number . '.pdf', $this->transport->sent[0]['attachments'][0]['name']);
    }

    public function test_credit_note_templates_exist_in_all_languages(): void
    {
        foreach (['invoice', 'credit_note'] as $type) {
            foreach (['de', 'pl', 'en'] as $lang) {
                $this->assertFileExists(PdfFixture::ROOT . "/templates/mail/$type.$lang.twig");
            }
        }
    }

    public function test_not_configured_sends_nothing_and_logs_nothing(): void
    {
        foreach (['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass_enc', 'smtp_from_addr'] as $col) {
            $this->configureSmtp($this->fx->companyA);
            $this->fx->db->exec("UPDATE companies SET $col = NULL WHERE id = ?", [$this->fx->companyA]);
            $this->assertFalse($this->mailer()->preview($this->doc)['configured'], $col);
            try {
                $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', 'Text');
                $this->fail("expected MailNotConfigured without $col");
            } catch (MailNotConfigured) {
            }
        }
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, $this->rows('email_log'));
        $this->assertSame(0, $this->rows('login_attempts'));
    }

    public function test_draft_is_refused_for_preview_and_send(): void
    {
        $draft = $this->fx->draft($this->fx->companyA, $this->customer);
        $calls = [
            'preview' => fn () => $this->mailer()->preview($draft),
            'send' => fn () => $this->mailer()->send($draft, 'kunde@example.de', 'Betreff', 'Text'),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("$name of a draft must be refused");
            } catch (PdfNotAvailable) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, $this->rows('email_log'));
    }

    public function test_foreign_unknown_and_unsupported_documents_are_refused_identically(): void
    {
        $other = $this->fx->issued($this->fx->companyB, $this->fx->customer($this->fx->companyB));
        $offer = $this->fx->service($this->fx->companyA)->createDraft('offer', ['customer_id' => $this->customer, 'lines' => PdfFixture::makietaLines()]);
        $this->fx->service($this->fx->companyA)->issue($offer, new \DateTimeImmutable('2026-11-18 10:00:00', new \DateTimeZone('Europe/Berlin')), true);
        foreach ([$other, 987654, $offer] as $id) {
            try {
                $this->mailer()->preview($id);
                $this->fail("preview $id");
            } catch (DocumentNotFound) {
            }
            try {
                $this->mailer()->send($id, 'kunde@example.de', 'Betreff', 'Text');
                $this->fail("send $id");
            } catch (DocumentNotFound) {
            }
        }
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, $this->rows('email_log'));
    }

    /** @return array<string, array{string}> */
    public static function badRecipients(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'no dot in domain' => ['a@b'],
            'comma list' => ['a@b.de,c@d.de'],
            'semicolon list' => ['a@b.de;c@d.de'],
            'space list' => ['a@b.de c@d.de'],
            'display name' => ['"x" <a@b.de>'],
            'angle brackets' => ['<a@b.de>'],
            'crlf bcc' => ["a@b.de\r\nBcc: x@y.de"],
            'lf bcc' => ["a@b.de\nBcc: x@y.de"],
            'trailing crlf' => ["a@b.de\r\n"],
            'nul' => ["a@b.de\0"],
            'tab' => ["a@b.de\t"],
            'two at signs' => ['a@b@c.de'],
            'long local part' => [str_repeat('a', 300) . '@example.com'],
            'local part 65' => [str_repeat('a', 65) . '@example.com'],
            'long address' => ['a@' . str_repeat('a', 60) . '.' . str_repeat('b', 60) . '.' . str_repeat('c', 60) . '.' . str_repeat('d', 60) . '.' . str_repeat('e', 60) . '.de'],
            'unicode idn domain (punycode only)' => ['info@müller.de'],
            'unicode local part' => ['jürgen@example.de'],
            'invalid utf8' => ["a\xC3\x28@b.de"],
        ];
    }

    #[DataProvider('badRecipients')]
    public function test_invalid_recipient_rejected(string $to): void
    {
        try {
            $this->mailer()->send($this->doc, $to, 'Betreff', 'Text');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('mail.err_to', $e->errors['to'] ?? null);
        }
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, $this->rows('email_log'));
    }

    public function test_valid_recipients_pass(): void
    {
        foreach (['a@b.de', 'max.mustermann+rechnung@sub.example.com', 'info@xn--mller-kva.de', ' kunde@example.de ', 'a%0a@example.de'] as $to) {
            $this->mailer()->send($this->doc, $to, 'Betreff', 'Text');
            $sent = end($this->transport->sent);
            $this->assertSame(trim($to), $sent['to']);
            $this->assertSame(1, preg_match('/\A[^\x00-\x20,;<>"]+\z/', $sent['to']));
        }
    }

    /** @return array<string, array{string}> */
    public static function badSubjects(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'crlf bcc' => ["Hallo\r\nBcc: x@y.de"],
            'lf' => ["Hallo\nBcc: x@y.de"],
            'cr' => ["Hallo\rBcc: x@y.de"],
            'nul' => ["Hallo\0"],
            'tab' => ["Hal\tlo"],
            'esc' => ["Hal\x1Blo"],
            'del' => ["Hal\x7Flo"],
            'unicode line separator' => ["Hal\u{2028}lo"],
            'next line' => ["Hal\u{0085}lo"],
            'too long' => [str_repeat('a', 201)],
            'invalid utf8' => ["Hallo \xC3\x28"],
        ];
    }

    #[DataProvider('badSubjects')]
    public function test_invalid_subject_rejected(string $subject): void
    {
        try {
            $this->mailer()->send($this->doc, 'kunde@example.de', $subject, 'Text');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('mail.err_subject', $e->errors['subject'] ?? null);
        }
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, $this->rows('email_log'));
    }

    public function test_subject_limits(): void
    {
        $this->mailer()->send($this->doc, 'kunde@example.de', str_repeat('ä', 200), 'Text');
        $this->assertSame(str_repeat('ä', 200), $this->transport->sent[0]['subject']);
        $this->mailer()->send($this->doc, 'kunde@example.de', 'x', 'Text');
        $this->assertCount(2, $this->transport->sent);
    }

    /** @return array<string, array{string}> */
    public static function badBodies(): array
    {
        return [
            'empty' => [''],
            'blank' => [" \n \r\n "],
            'nul' => ["Hallo\0Welt"],
            'too long' => [str_repeat('a', 10001)],
            'invalid utf8' => ["Hallo \xC3\x28"],
            'control char' => ["Hallo\x07"],
        ];
    }

    #[DataProvider('badBodies')]
    public function test_invalid_body_rejected(string $body): void
    {
        try {
            $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', $body);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('mail.err_body', $e->errors['body'] ?? null);
        }
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, $this->rows('email_log'));
    }

    public function test_body_limit_is_10000_characters(): void
    {
        $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', str_repeat('ä', 10000));
        $this->assertCount(1, $this->transport->sent);
    }

    public function test_all_errors_are_reported_together(): void
    {
        try {
            $this->mailer()->send($this->doc, 'nope', '', '');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['to', 'subject', 'body'], array_keys($e->errors));
        }
    }

    public function test_rate_limit_21st_send_in_ten_minutes_is_refused(): void
    {
        $m = $this->mailer();
        for ($i = 1; $i <= 20; $i++) {
            $this->now += 20;
            $m->send($this->doc, 'kunde@example.de', 'Betreff ' . $i, 'Text');
        }
        $this->assertCount(20, $this->transport->sent);
        try {
            $m->send($this->doc, 'kunde@example.de', 'Betreff 21', 'Text');
            $this->fail('expected rate limit');
        } catch (ValidationException $e) {
            $this->assertSame('mail.err_rate', $e->errors['rate'] ?? null);
        }
        $this->assertCount(20, $this->transport->sent);
        $this->assertSame(20, $this->rows('email_log'));
        // The window slides: 10 minutes after the first send there is room again (fake clock, no sleep).
        $this->now += 601;
        $m->send($this->doc, 'kunde@example.de', 'Betreff 22', 'Text');
        $this->assertCount(21, $this->transport->sent);
    }

    public function test_failed_attempts_count_towards_the_limit(): void
    {
        $this->transport->failWith = 'connect';
        for ($i = 0; $i < 20; $i++) {
            try {
                $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', 'Text');
            } catch (MailSendFailed) {
            }
        }
        $this->expectException(ValidationException::class);
        $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', 'Text');
    }

    public function test_rate_limit_is_per_company(): void
    {
        $other = $this->fx->issued($this->fx->companyB, $this->fx->customer($this->fx->companyB));
        $this->configureSmtp($this->fx->companyB);
        for ($i = 0; $i < 20; $i++) {
            $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', 'Text');
        }
        $this->mailer($this->fx->companyB)->send($other, 'kunde@example.de', 'Betreff', 'Text');
        $this->assertCount(21, $this->transport->sent);
    }

    public function test_invalid_input_does_not_use_up_the_rate_limit(): void
    {
        for ($i = 0; $i < 30; $i++) {
            try {
                $this->mailer()->send($this->doc, 'bad', 'Betreff', 'Text');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, $this->rows('login_attempts'));
    }

    public function test_suspended_company_can_still_send_issued_documents(): void
    {
        $this->fx->db->exec("UPDATE companies SET access_status = 'suspended' WHERE id = ?", [$this->fx->companyA]);
        $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', 'Text');
        $this->assertCount(1, $this->transport->sent);
    }

    public function test_company_b_cannot_log_for_a_document_of_a(): void
    {
        $this->configureSmtp($this->fx->companyB);
        try {
            $this->mailer($this->fx->companyB)->send($this->doc, 'kunde@example.de', 'Betreff', 'Text');
            $this->fail('expected DocumentNotFound');
        } catch (DocumentNotFound) {
        }
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, $this->rows('email_log'));
    }

    public function test_default_body_is_a_valid_send_body(): void
    {
        $d = $this->defaults();
        $this->mailer()->send($this->doc, $d['to'], $d['subject'], $d['body']);
        $this->assertSame($d['body'], $this->transport->sent[0]['body']);
    }

    public function test_html_in_names_is_plain_text_in_the_body(): void
    {
        $c = $this->fx->customer($this->fx->companyA, ['name' => 'Tom & <b>Jerry</b> "KG"', 'email' => 'tj@example.com']);
        $doc = $this->fx->issued($this->fx->companyA, $c);
        $body = $this->mailer()->preview($doc)['body'];
        $this->assertStringContainsString('Tom & <b>Jerry</b> "KG"', $body, 'plain text: nothing is HTML-escaped into entities');
        $this->assertStringNotContainsString('&amp;', $body);
        $this->assertStringNotContainsString('&lt;', $body);
    }

    // ---- settings test mail ----

    public function test_test_mail_goes_to_the_given_address_without_attachment_and_is_audited(): void
    {
        $this->mailer()->sendTest('jan@example.pl', 'pl');
        $call = $this->transport->sent[0];
        $this->assertSame('jan@example.pl', $call['to']);
        $this->assertSame([], $call['attachments']);
        $this->assertSame(self::PASSWORD, $call['smtp']['pass']);
        $this->assertNotSame('', $call['subject']);
        $audit = $this->fx->db->one("SELECT * FROM audit_log WHERE action = 'settings.mail_test'");
        $this->assertSame(['status' => 'sent'], json_decode((string) $audit['detail'], true));
        $this->assertSame(0, $this->rows('email_log'));
    }

    public function test_test_mail_failure_is_audited_with_the_code_only(): void
    {
        $this->transport->failWith = 'tls';
        try {
            $this->mailer()->sendTest('jan@example.pl', 'de');
            $this->fail('expected MailSendFailed');
        } catch (MailSendFailed $e) {
            $this->assertSame('tls', $e->reason);
        }
        $audit = $this->fx->db->one("SELECT detail FROM audit_log WHERE action = 'settings.mail_test'");
        $this->assertSame(['status' => 'failed', 'reason' => 'tls'], json_decode((string) $audit['detail'], true));
        $this->assertStringNotContainsString(self::PASSWORD, $this->dump());
    }

    public function test_test_mail_requires_configuration_and_a_sane_address(): void
    {
        try {
            $this->mailer()->sendTest("a@b.de\r\nBcc: x@y.de", 'de');
            $this->fail('expected ValidationException');
        } catch (ValidationException) {
        }
        $this->fx->db->exec('UPDATE companies SET smtp_host = NULL WHERE id = ?', [$this->fx->companyA]);
        $this->expectException(MailNotConfigured::class);
        try {
            $this->mailer()->sendTest('jan@example.pl', 'de');
        } finally {
            $this->assertSame([], $this->transport->sent);
        }
    }

    public function test_test_mail_is_limited_to_three_per_ten_minutes(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->mailer()->sendTest('jan@example.pl', 'de');
        }
        try {
            $this->mailer()->sendTest('jan@example.pl', 'de');
            $this->fail('expected rate limit');
        } catch (ValidationException $e) {
            $this->assertSame('mail.err_rate', $e->errors['rate'] ?? null);
        }
        $this->assertCount(3, $this->transport->sent);
        $this->now += 601;
        $this->mailer()->sendTest('jan@example.pl', 'de');
        $this->assertCount(4, $this->transport->sent);
    }

    public function test_test_mails_do_not_use_up_the_document_limit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->mailer()->sendTest('jan@example.pl', 'de');
        }
        $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', 'Text');
        $this->assertCount(4, $this->transport->sent);
    }

    /** @return array<string, array{string, int}> */
    public static function badTargets(): array
    {
        return [
            'loopback ip' => ['127.0.0.1', 587], 'localhost' => ['localhost', 587], 'private' => ['10.0.0.5', 25], 'metadata' => ['169.254.169.254', 587],
            'ipv6 loopback' => ['::1', 587], 'mapped loopback' => ['::ffff:127.0.0.1', 587], 'unresolvable' => ['nirgendwo.example', 587],
            'redis port' => ['smtp.firma.example', 6379], 'ssh port' => ['smtp.firma.example', 22],
        ];
    }

    #[DataProvider('badTargets')]
    public function test_ssrf_targets_never_reach_the_transport_and_fail_as_connect(string $host, int $port): void
    {
        $this->fx->db->exec('UPDATE companies SET smtp_host = ?, smtp_port = ? WHERE id = ?', [$host, $port, $this->fx->companyA]);
        try {
            $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', 'Text');
            $this->fail('expected MailSendFailed');
        } catch (MailSendFailed $e) {
            $this->assertSame('connect', $e->reason);
        }
        $this->assertSame([], $this->transport->sent, 'transport not called');
        $this->assertSame('connect', $this->fx->db->one('SELECT error FROM email_log')['error']);
        $this->assertStringNotContainsString(self::PASSWORD, $this->dump());
        try {
            $this->mailer()->sendTest('jan@example.pl', 'de');
            $this->fail('expected MailSendFailed for the test mail');
        } catch (MailSendFailed $e) {
            $this->assertSame('connect', $e->reason);
        }
        $this->assertSame([], $this->transport->sent);
    }

    public function test_allowed_ports_pass_the_guard(): void
    {
        foreach ([25, 465, 587, 2525] as $port) {
            $this->fx->db->exec('UPDATE companies SET smtp_port = ? WHERE id = ?', [$port, $this->fx->companyA]);
            $this->mailer()->send($this->doc, 'kunde@example.de', 'Betreff', 'Text');
        }
        $this->assertCount(4, $this->transport->sent);
    }

    public function test_buyer_email_domain_is_prefilled_in_punycode(): void
    {
        if (!function_exists('idn_to_ascii')) {
            $this->markTestSkipped('intl idn_to_ascii is not available.');
        }
        $cases = ['x@bücher.de' => 'x@xn--bcher-kva.de', 'info@example.com' => 'info@example.com', 'jürgen@bücher.de' => ''];
        foreach ($cases as $stored => $expected) {
            $buyer = json_decode((string) $this->fx->db->one('SELECT buyer_json FROM documents WHERE id = ?', [$this->doc])['buyer_json'], true);
            $buyer['email'] = $stored;
            $this->fx->db->exec('UPDATE documents SET buyer_json = ? WHERE id = ?', [json_encode($buyer, JSON_UNESCAPED_UNICODE), $this->doc]);
            $this->assertSame($expected, $this->mailer()->preview($this->doc)['to'], $stored);
        }
    }
}
