<?php
declare(strict_types=1);

namespace Tests\Mail;

use App\Mail\MailSendFailed;
use App\Mail\SmtpTransport;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SmtpTransport is inspected offline: the PHPMailer object is built and checked, never connected. */
final class SmtpTransportTest extends TestCase
{
    /** @return array{host: string, port: int, user: string, pass: string, secure: string} */
    private static function smtp(int $port = 465, string $secure = 'ssl'): array
    {
        return ['host' => 'smtp.example.com', 'port' => $port, 'user' => 'user@example.com', 'pass' => 'S3cr3t-Passw0rd!', 'secure' => $secure];
    }

    private function build(array $smtp, string $subject = 'Rechnung RE-1', string $to = 'kunde@example.de', array $att = []): PHPMailer
    {
        return (new SmtpTransport())->build($smtp, 'info@firma.de', 'Firma GmbH', $to, $subject, "Hallo\n", $att);
    }

    public function test_implicit_tls_on_465(): void
    {
        $m = $this->build(self::smtp(465, 'ssl'));
        $this->assertSame('smtp', $m->Mailer);
        $this->assertSame(PHPMailer::ENCRYPTION_SMTPS, $m->SMTPSecure);
        $this->assertSame('smtp.example.com', $m->Host);
        $this->assertSame(465, $m->Port);
    }

    public function test_starttls_on_other_ports_and_never_plaintext(): void
    {
        foreach ([587, 25, 2525] as $port) {
            $m = $this->build(self::smtp($port, 'tls'));
            $this->assertSame(PHPMailer::ENCRYPTION_STARTTLS, $m->SMTPSecure, "port $port");
            $this->assertSame($port, $m->Port);
        }
    }

    public function test_unknown_secure_mode_is_refused_not_downgraded(): void
    {
        foreach (['', 'none', 'plain', 'SSL'] as $mode) {
            try {
                $this->build(self::smtp(25, $mode));
                $this->fail("mode '$mode' must be refused");
            } catch (MailSendFailed $e) {
                $this->assertSame('tls', $e->reason);
            }
        }
    }

    public function test_certificate_verification_stays_on_and_nothing_is_echoed(): void
    {
        $m = $this->build(self::smtp());
        $this->assertSame([], $m->SMTPOptions, 'no verify_peer/verify_peer_name/allow_self_signed overrides');
        $this->assertSame(0, $m->SMTPDebug);
        $this->assertFalse($m->SMTPKeepAlive);
        $this->assertTrue($m->SMTPAuth);
        $this->assertSame('user@example.com', $m->Username);
        $this->assertSame('S3cr3t-Passw0rd!', $m->Password);
    }

    public function test_timeouts_are_short(): void
    {
        $m = $this->build(self::smtp());
        $this->assertLessThanOrEqual(15, $m->Timeout);
        $this->assertGreaterThan(0, $m->Timeout);
    }

    public function test_utf8_plain_text_message_with_encoded_subject(): void
    {
        $m = $this->build(self::smtp(), "Faktura FV/2026/1 – zażółć gęślą jaźń");
        $this->assertSame('UTF-8', $m->CharSet);
        $this->assertTrue($m->preSend());
        $mime = $m->getSentMIMEMessage();
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $mime);
        $this->assertStringNotContainsString('text/html', $mime);
        $this->assertMatchesRegularExpression('/^Subject: =\?UTF-8\?[BQ]\?/m', $mime);
        $unfolded = (string) preg_replace('/\r\n[ \t]+/', '', $mime);
        $this->assertSame(1, preg_match('/^Subject: (.*)$/m', $unfolded, $mm));
        $this->assertSame('Faktura FV/2026/1 – zażółć gęślą jaźń', iconv_mime_decode('Subject: ' . rtrim($mm[1]), 0, 'UTF-8') === false ? '' : substr(iconv_mime_decode('Subject: ' . rtrim($mm[1]), 0, 'UTF-8'), 9));
        $this->assertStringContainsString('From: Firma GmbH <info@firma.de>', $mime);
        $this->assertStringNotContainsString('Reply-To', $mime);
    }

    public function test_attachment_is_attached_with_its_name(): void
    {
        $m = $this->build(self::smtp(), 'S', 'kunde@example.de', [['name' => 'RE-2026-001.pdf', 'content' => "%PDF-1.4\nx", 'mime' => 'application/pdf']]);
        $this->assertTrue($m->preSend());
        $mime = $m->getSentMIMEMessage();
        $this->assertStringContainsString('Content-Type: application/pdf; name=RE-2026-001.pdf', $mime);
        $this->assertStringContainsString('Content-Disposition: attachment; filename=RE-2026-001.pdf', $mime);
    }

    /** @return array<string, array{string, string, string}> */
    public static function injections(): array
    {
        return [
            'crlf subject' => ["Hi\r\nBcc: evil@example.org", 'kunde@example.de', 'Hallo'],
            'lf subject' => ["Hi\nBcc: evil@example.org", 'kunde@example.de', 'Hallo'],
            'nul subject' => ["Hi\0", 'kunde@example.de', 'Hallo'],
            'crlf to' => ['Hi', "kunde@example.de\r\nBcc: evil@example.org", 'Hallo'],
            'two recipients' => ['Hi', 'a@example.de,b@example.de', 'Hallo'],
            'display name' => ['Hi', '"X" <a@example.de>', 'Hallo'],
        ];
    }

    #[DataProvider('injections')]
    public function test_header_injection_is_refused_before_anything_is_built(string $subject, string $to): void
    {
        $this->expectException(MailSendFailed::class);
        // send() would connect after build(); build() already refuses, so no network is touched.
        (new SmtpTransport())->send(self::smtp(), 'info@firma.de', 'Firma', $to, $subject, 'x', []);
    }

    public function test_from_name_with_crlf_is_refused(): void
    {
        $this->expectException(MailSendFailed::class);
        (new SmtpTransport())->build(self::smtp(), 'info@firma.de', "Firma\r\nBcc: x@y.de", 'k@example.de', 'S', 'x', []);
    }

    public function test_host_with_scheme_or_list_is_refused(): void
    {
        foreach (['ssl://smtp.example.com', 'a.example.com;b.example.com', 'smtp.example.com:25', ''] as $host) {
            try {
                $this->build(['host' => $host] + self::smtp());
                $this->fail("host '$host' must be refused");
            } catch (MailSendFailed $e) {
                $this->assertSame('connect', $e->reason);
            }
        }
    }

    /** @return array<string, array{string, string}> */
    public static function messages(): array
    {
        return [
            'auth' => ['SMTP Error: Could not authenticate.', 'auth'],
            'recipients' => ['SMTP Error: The following recipients failed: a@b.de: 550 no such user', 'recipient'],
            'invalid address' => ['Invalid address:  (to): x', 'recipient'],
            'connect host' => ['SMTP Error: Could not connect to SMTP host. Failed to connect to server', 'connect'],
            'connect failed' => ['SMTP connect() failed. https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting', 'connect'],
            'starttls' => ['STARTTLS not accepted from server', 'tls'],
            'tls handshake' => ['stream_socket_enable_crypto(): SSL operation failed: certificate verify failed', 'tls'],
            'openssl missing' => ['Extension missing: openssl', 'tls'],
            'other' => ['SMTP Error: data not accepted.', 'other'],
        ];
    }

    #[DataProvider('messages')]
    public function test_phpmailer_messages_map_to_reason_codes_only(string $message, string $reason): void
    {
        $this->assertSame($reason, SmtpTransport::classify($message));
    }

    public function test_failed_send_exposes_only_the_code(): void
    {
        $transport = new class () extends SmtpTransport {
            protected function newMailer(): PHPMailer
            {
                return new class (true) extends PHPMailer {
                    public function postSend(): bool
                    {
                        throw new \PHPMailer\PHPMailer\Exception('SMTP Error: Could not authenticate. S3cr3t-Passw0rd! user@example.com 535 raw banner');
                    }
                };
            }
        };
        try {
            $transport->send(self::smtp(), 'a@example.com', 'A', 'b@example.com', 'S', 'x', []);
            $this->fail('expected failure');
        } catch (MailSendFailed $e) {
            $this->assertSame('auth', $e->reason);
            $this->assertStringNotContainsString('S3cr3t', $e->getMessage());
            $this->assertStringNotContainsString('banner', $e->getMessage());
            $this->assertNull($e->getPrevious(), 'the PHPMailer exception (with server text) is not chained');
        }
    }

    public function test_unexpected_errors_become_other(): void
    {
        $transport = new class () extends SmtpTransport {
            protected function newMailer(): PHPMailer
            {
                return new class (true) extends PHPMailer {
                    public function postSend(): bool
                    {
                        throw new \RuntimeException('boom S3cr3t');
                    }
                };
            }
        };
        $this->expectException(MailSendFailed::class);
        $transport->send(self::smtp(), 'a@example.com', 'A', 'b@example.com', 'S', 'x', []);
    }
}
