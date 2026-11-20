<?php
declare(strict_types=1);

namespace App\Mail;

/**
 * Hands one finished plain-text message to a mail server. Implementations never decide anything
 * (recipient, text, attachments are final) and must raise only MailSendFailed, which carries a
 * short secret-free reason code.
 */
interface MailTransport
{
    /**
     * @param array{host: string, port: int, user: string, pass: string, secure: string} $smtp pass is the
     *        DECRYPTED password (handed over at the last moment, never logged); secure is 'ssl' (implicit
     *        TLS, port 465) or 'tls' (STARTTLS required); there is no plaintext mode
     * @param list<array{name: string, content: string, mime: string}> $attachments name is already sanitised
     * @throws MailSendFailed
     */
    public function send(array $smtp, string $from, string $fromName, string $to, string $subject, string $body, array $attachments): void;
}
