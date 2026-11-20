<?php
declare(strict_types=1);

namespace Tests\Mail;

use App\Mail\MailSendFailed;
use App\Mail\SmtpTargetPolicy;
use App\Mail\SmtpTransport;
use Tests\Support\FakeResolver;
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

    private function transport(?FakeResolver $r = null, bool $allowPrivate = false): SmtpTransport
    {
        return new SmtpTransport(new SmtpTargetPolicy($r ?? FakeResolver::publicHosts(), $allowPrivate));
    }

    private function build(array $smtp, string $subject = 'Rechnung RE-1', string $to = 'kunde@example.de', array $att = [], string $body = "Hallo\n"): PHPMailer
    {
        return $this->transport()->build($smtp, 'info@firma.de', 'Firma GmbH', $to, $subject, $body, $att);
    }

    public function test_implicit_tls_on_465(): void
    {
        $m = $this->build(self::smtp(465, 'ssl'));
        $this->assertSame('smtp', $m->Mailer);
        $this->assertSame(PHPMailer::ENCRYPTION_SMTPS, $m->SMTPSecure);
        $this->assertSame('93.184.216.34', $m->Host, 'connects to the checked IP');
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
        // Only the certificate name is pinned to the configured host; peer verification itself stays at PHP's secure default.
        $this->assertSame(['ssl' => ['peer_name' => 'smtp.example.com']], $m->SMTPOptions);
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
        $this->assertSame('Faktura FV/2026/1 – zażółć gęślą jaźń', iconv_mime_decode('Subject: ' . rtrim($mm[1]), 0, 'UTF-8') === false ? null : substr(iconv_mime_decode('Subject: ' . rtrim($mm[1]), 0, 'UTF-8'), strlen('Subject: ')));
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
            'crlf subject' => ["Hi\r\nBcc: evil@example.org", 'kunde@example.de'],
            'lf subject' => ["Hi\nBcc: evil@example.org", 'kunde@example.de'],
            'nul subject' => ["Hi\0", 'kunde@example.de'],
            'crlf to' => ['Hi', "kunde@example.de\r\nBcc: evil@example.org"],
            'two recipients' => ['Hi', 'a@example.de,b@example.de'],
            'display name' => ['Hi', '"X" <a@example.de>'],
        ];
    }

    #[DataProvider('injections')]
    public function test_header_injection_is_refused_before_any_connection(string $subject, string $to): void
    {
        $resolver = FakeResolver::publicHosts();
        $this->expectException(MailSendFailed::class);
        try {
            // send() builds the message first; build() refuses, so no socket is ever opened.
            $this->transport($resolver)->send(self::smtp(), 'info@firma.de', 'Firma', $to, $subject, 'x', []);
        } finally {
            $this->assertSame(0, $resolver->calls, 'refused before even resolving the host');
        }
    }

    public function test_from_name_with_crlf_is_refused(): void
    {
        $this->expectException(MailSendFailed::class);
        $this->transport()->build(self::smtp(), 'info@firma.de', "Firma\r\nBcc: x@y.de", 'k@example.de', 'S', 'x', []);
    }

    public function test_host_with_scheme_or_list_is_refused(): void
    {
        foreach (['ssl://smtp.example.com', 'a.example.com;b.example.com', 'smtp.example.com:25', '', 'nur.ein.unbekannter.host'] as $host) {
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
        $transport = new class (new SmtpTargetPolicy(FakeResolver::publicHosts())) extends SmtpTransport {
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
        $transport = new class (new SmtpTargetPolicy(FakeResolver::publicHosts())) extends SmtpTransport {
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
    public function test_from_address_problems_are_other_not_recipient(): void
    {
        foreach (['no-address', "a@b.de\r\nBcc: x@y.de"] as $from) {
            try {
                $this->transport()->build(self::smtp(), $from, 'Firma', 'kunde@example.de', 'S', 'x', []);
                $this->fail('from must be refused');
            } catch (MailSendFailed $e) {
                $this->assertSame('other', $e->reason);
            }
        }
        try {
            $this->transport()->build(self::smtp(), 'info@firma.de', 'Firma', 'kein-empfaenger', 'S', 'x', []);
        } catch (MailSendFailed $e) {
            $this->assertSame('recipient', $e->reason);
        }
    }

    public function test_body_goes_out_with_crlf_line_breaks_and_round_trips_exactly(): void
    {
        $long = str_repeat('ąęłóśżźćńäöüß ', 15);
        $this->assertGreaterThan(200, mb_strlen($long));
        $body = "Zeile1\nZeile2\n\nZeile4\n" . $long . "\nGrüße aus Köln: zażółć gęślą jaźń\n";
        $m = $this->build(self::smtp(), 'S', 'kunde@example.de', [], $body);
        $this->assertTrue($m->preSend());
        $mime = $m->getSentMIMEMessage();
        $this->assertStringNotContainsString('=0A', $mime);
        $this->assertStringNotContainsString('=0D', $mime);
        $this->assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $mime);
        $part = substr($mime, (int) strpos($mime, "\r\n\r\n") + 4);
        $decoded = quoted_printable_decode($part);
        $this->assertStringStartsWith("Zeile1\r\nZeile2\r\n\r\nZeile4\r\n", $decoded);
        $this->assertSame(str_replace("\n", "\r\n", $body), rtrim($decoded, "\r\n") . "\r\n");
        // A body that already has CRLF or stray CR never becomes CRCRLF.
        $m = $this->build(self::smtp(), 'S', 'kunde@example.de', [], "a\r\nb\rc\n");
        $m->preSend();
        $decoded = quoted_printable_decode(substr($m->getSentMIMEMessage(), (int) strpos($m->getSentMIMEMessage(), "\r\n\r\n") + 4));
        $this->assertStringStartsWith("a\r\nb\r\nc\r\n", $decoded);
        $this->assertStringNotContainsString("\r\r", $decoded);
    }

    /** @return array<string, array{string, array<string, list<string>>}> */
    public static function refusedHosts(): array
    {
        $map = [
            'private.example' => ['10.1.2.3'], 'mixed.example' => ['93.184.216.34', '10.0.0.9'], 'v6mixed.example' => ['93.184.216.34', 'fe80::1'],
            'mapped.example' => ['::ffff:127.0.0.1'], 'meta.example' => ['169.254.169.254'],
        ];
        $hosts = ['127.0.0.1', 'localhost', 'LOCALHOST.', 'x.localhost', '10.0.0.5', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254', '0.0.0.0',
            '100.64.0.1', '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:7f00:1', '::127.0.0.1',
            '64:ff9b::7f00:1', '64:ff9b::a00:1', '2002:7f00:1::1', '2002:a9fe:a9fe::', '2001:0:4136:e378:8000:63bf:3fff:fdd2', 'ff02::1',
            'private.example', 'mixed.example', 'v6mixed.example', 'mapped.example', 'meta.example', 'unresolvable.example'];
        $out = [];
        foreach ($hosts as $h) {
            $out[$h] = [$h, $map];
        }
        return $out;
    }

    /** @param array<string, list<string>> $map */
    #[DataProvider('refusedHosts')]
    public function test_non_public_or_unresolvable_hosts_are_refused_before_any_socket(string $host, array $map): void
    {
        $t = $this->transport(new FakeResolver($map));
        try {
            $t->build(['host' => $host] + self::smtp(587, 'tls'), 'info@firma.de', 'Firma', 'kunde@example.de', 'S', 'x', []);
            $this->fail("$host must be refused");
        } catch (MailSendFailed $e) {
            $this->assertSame('connect', $e->reason);
        }
        // send() uses the same path: it fails in build(), before PHPMailer could open a connection.
        $this->expectException(MailSendFailed::class);
        $t->send(['host' => $host] + self::smtp(587, 'tls'), 'info@firma.de', 'Firma', 'kunde@example.de', 'S', 'x', []);
    }

    public function test_public_addresses_pass_and_ipv4_is_preferred(): void
    {
        $r = new FakeResolver(['dual.example' => ['2606:2800:220:1::1', '93.184.216.34'], 'six.example' => ['2606:2800:220:1::1']]);
        $this->assertSame('93.184.216.34', $this->transport($r)->build(['host' => 'dual.example'] + self::smtp(), 'a@firma.de', 'F', 'k@example.de', 'S', 'x', [])->Host);
        $m = $this->transport($r)->build(['host' => 'six.example'] + self::smtp(), 'a@firma.de', 'F', 'k@example.de', 'S', 'x', []);
        $this->assertSame('[2606:2800:220:1::1]', $m->Host);
        $this->assertSame('six.example', $m->SMTPOptions['ssl']['peer_name']);
        $this->assertSame('93.184.216.34', $this->transport($r)->build(['host' => '93.184.216.34'] + self::smtp(), 'a@firma.de', 'F', 'k@example.de', 'S', 'x', [])->Host);
    }

    /** @return array<string, array{int, bool}> */
    public static function ports(): array
    {
        return [
            '22' => [22, false], '80' => [80, false], '443' => [443, false], '6379' => [6379, false], '3306' => [3306, false], '0' => [0, false], '65535' => [65535, false],
            '25' => [25, true], '465' => [465, true], '587' => [587, true], '2525' => [2525, true],
        ];
    }

    #[DataProvider('ports')]
    public function test_port_allow_list(int $port, bool $allowed): void
    {
        $resolver = FakeResolver::publicHosts();
        try {
            $m = $this->transport($resolver)->build(self::smtp($port, $port === 465 ? 'ssl' : 'tls'), 'a@firma.de', 'F', 'k@example.de', 'S', 'x', []);
            $this->assertTrue($allowed);
            $this->assertSame($port, $m->Port);
        } catch (MailSendFailed $e) {
            $this->assertFalse($allowed);
            $this->assertSame('connect', $e->reason);
            $this->assertSame(0, $resolver->calls, 'a refused port is not even resolved');
        }
    }

    public function test_private_hosts_opt_in_skips_only_the_address_check(): void
    {
        $t = $this->transport(new FakeResolver(), true);
        $m = $t->build(['host' => '192.168.1.10', 'port' => 587] + self::smtp(587, 'tls'), 'a@firma.de', 'F', 'k@example.de', 'S', 'x', []);
        $this->assertSame('192.168.1.10', $m->Host);
        $this->assertSame(PHPMailer::ENCRYPTION_STARTTLS, $m->SMTPSecure);
        $this->assertArrayNotHasKey('verify_peer', $m->SMTPOptions['ssl']);
        $this->expectException(MailSendFailed::class);
        $t->build(['host' => '192.168.1.10', 'port' => 6379] + self::smtp(6379, 'tls'), 'a@firma.de', 'F', 'k@example.de', 'S', 'x', []);
    }

    public function test_peer_verification_options_are_never_weakened(): void
    {
        $opts = $this->build(self::smtp())->SMTPOptions['ssl'];
        $this->assertSame(['peer_name'], array_keys($opts));
    }
}
