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
 * - every failure becomes a MailSendFailed with a short reason code; the PHPMailer exception text
 *   (server banner, addresses, hints about credentials) is neither chained nor stored.
 *
 * build() creates the configured PHPMailer without connecting, so tests can inspect it offline.
 */
class SmtpTransport implements MailTransport
{
    public const TIMEOUT = 15;

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
        foreach ([$from, $to] as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new MailSendFailed(MailSendFailed::RECIPIENT);
            }
        }
        $secure = match ($smtp['secure']) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            default => throw new MailSendFailed(MailSendFailed::TLS),
        };
        if (preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?\z/', $smtp['host']) !== 1 || $smtp['port'] < 1 || $smtp['port'] > 65535) {
            throw new MailSendFailed(MailSendFailed::CONNECT);
        }

        $m = $this->newMailer();
        try {
            $m->setLanguage('en');
            $m->isSMTP();
            $m->Host = $smtp['host'];
            $m->Port = $smtp['port'];
            $m->SMTPAuth = true;
            $m->Username = $smtp['user'];
            $m->Password = $smtp['pass'];
            $m->SMTPSecure = $secure;
            $m->SMTPAutoTLS = true; // only relevant without SMTPSecure; SMTPSecure is always set here
            $m->SMTPOptions = [];   // PHPMailer default: verify_peer, verify_peer_name
            $m->SMTPKeepAlive = false;
            $m->SMTPDebug = 0;
            $m->Debugoutput = static function (): void {
            };
            $m->Timeout = self::TIMEOUT;
            $m->CharSet = 'UTF-8';
            $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $m->XMailer = ' ';
            $m->isHTML(false);
            $m->setFrom($from, $fromName);
            $m->addAddress($to);
            $m->Subject = $subject;
            $m->Body = $body;
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
