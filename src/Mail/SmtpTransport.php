<?php
declare(strict_types=1);

namespace App\Mail;

use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * SMTP delivery with PHPMailer. Security rules (not configurable):
 * - transport security is always on: 'ssl' = implicit TLS (port 465), 'tls' = STARTTLS that must
 *   succeed (PHPMailer aborts when the server does not offer it). Any other mode is refused, there
 *   is no plaintext fallback;
 * - PHPMailer's default peer and host-name verification stays on (SMTPOptions is left empty);
 * - connect/read timeout 15 s, no debug output, no keep-alive;
 * - plain text only (UTF-8, quoted-printable), no HTML part;
 * - addresses, from name and subject containing control characters are refused (header
 *   injection), the host must be a plain host name or IP (no scheme, no list, no port);
 * - SSRF guard (SmtpTargetPolicy): only ports 25/465/587/2525 and only hosts whose addresses are
 *   ALL public are used; the connection goes to the checked IP (no DNS rebinding) while the
 *   certificate is still verified against the configured host name (ssl peer_name; verify_peer
 *   and verify_peer_name stay at PHP's secure defaults). Refusals happen before any socket;
 * - the body is sent with CRLF line breaks (quoted-printable needs them);
 * - every failure becomes a MailSendFailed with a short reason code; the PHPMailer exception text
 *   (server banner, addresses, hints about credentials) is neither chained nor stored.
 *
 * build() creates the configured PHPMailer without connecting, so tests can inspect it offline.
 */
class SmtpTransport implements MailTransport
{
    public const TIMEOUT = 15;

    private SmtpTargetPolicy $policy;

    public function __construct(?SmtpTargetPolicy $policy = null)
    {
        $this->policy = $policy ?? new SmtpTargetPolicy();
    }

    public function send(array $smtp, string $from, string $fromName, string $to, string $subject, string $body, array $attachments): void
    {
        $mailer = $this->build($smtp, $from, $fromName, $to, $subject, $body, $attachments);
        try {
            $mailer->send();
        } catch (PhpMailerException $e) {
            throw new MailSendFailed(self::classify($e->getMessage()));
        } catch (\Throwable) {
            throw new MailSendFailed(MailSendFailed::OTHER);
        }
    }

    /**
     * The fully configured, not yet connected PHPMailer.
     *
     * @param array{host: string, port: int, user: string, pass: string, secure: string} $smtp
     * @param list<array{name: string, content: string, mime: string}> $attachments
     * @throws MailSendFailed for unusable settings or header-injection attempts
     */
    public function build(array $smtp, string $from, string $fromName, string $to, string $subject, string $body, array $attachments): PHPMailer
    {
        foreach ([$from, $fromName, $to, $subject, $smtp['host'], $smtp['user']] as $value) {
            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new MailSendFailed(MailSendFailed::OTHER);
            }
        }
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailSendFailed(MailSendFailed::OTHER);
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailSendFailed(MailSendFailed::RECIPIENT);
        }
        $secure = match ($smtp['secure']) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            default => throw new MailSendFailed(MailSendFailed::TLS),
        };
        $isIp = filter_var($smtp['host'], FILTER_VALIDATE_IP) !== false;
        if (!$isIp && preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?\z/', $smtp['host']) !== 1) {
            throw new MailSendFailed(MailSendFailed::CONNECT);
        }
        if (!SmtpTargetPolicy::portAllowed($smtp['port'])) {
            throw new MailSendFailed(MailSendFailed::CONNECT);
        }
        $address = $this->policy->connectAddress($smtp['host']) ?? throw new MailSendFailed(MailSendFailed::CONNECT);

        $m = $this->newMailer();
        try {
            $m->setLanguage('en');
            $m->isSMTP();
            $m->Host = $address;
            $m->Port = $smtp['port'];
            $m->SMTPAuth = true;
            $m->Username = $smtp['user'];
            $m->Password = $smtp['pass'];
            $m->SMTPSecure = $secure;
            $m->SMTPAutoTLS = true; // only relevant without SMTPSecure; SMTPSecure is always set here
            // Certificate name (and SNI) are checked against the configured host although we connect to
            // its checked IP. No verify_peer/verify_peer_name/allow_self_signed keys: PHP's secure defaults apply.
            $m->SMTPOptions = ['ssl' => ['peer_name' => $smtp['host']]];
            $m->SMTPKeepAlive = false;
            $m->SMTPDebug = 0;
            $m->Debugoutput = static function (): void {
            };
            $m->Timeout = self::TIMEOUT;
            $m->CharSet = 'UTF-8';
            $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $m->XMailer = ' ';
            $m->isHTML(false);
            try {
                $m->setFrom($from, $fromName);
            } catch (PhpMailerException) {
                throw new MailSendFailed(MailSendFailed::OTHER);
            }
            $m->addAddress($to);
            $m->Subject = $subject;
            // quoted_printable_encode() only knows CRLF as a line break (RFC 2045 6.7): never CRCRLF.
            $m->Body = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $body));
            foreach ($attachments as $a) {
                $m->addStringAttachment($a['content'], $a['name'], PHPMailer::ENCODING_BASE64, $a['mime']);
            }
        } catch (PhpMailerException $e) {
            throw new MailSendFailed(self::classify($e->getMessage()));
        }
        return $m;
    }

    /** Maps a PHPMailer message to a reason code. The message itself is never kept. */
    public static function classify(string $message): string
    {
        $m = strtolower($message);
        return match (true) {
            str_contains($m, 'authenticate') => MailSendFailed::AUTH,
            str_contains($m, 'recipients failed'), str_contains($m, 'invalid address') => MailSendFailed::RECIPIENT,
            str_contains($m, 'starttls'), str_contains($m, 'tls'), str_contains($m, 'ssl'), str_contains($m, 'certificate'),
            str_contains($m, 'extension missing') => MailSendFailed::TLS,
            str_contains($m, 'connect') => MailSendFailed::CONNECT,
            default => MailSendFailed::OTHER,
        };
    }

    /** Factory seam: tests return a PHPMailer double. Exceptions mode is required by build()/send(). */
    protected function newMailer(): PHPMailer
    {
        return new PHPMailer(true);
    }
}
