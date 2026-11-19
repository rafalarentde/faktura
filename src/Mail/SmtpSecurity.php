<?php
declare(strict_types=1);

namespace App\Mail;

/**
 * Transport security for company SMTP settings. There is no plaintext option: port 465 uses
 * implicit TLS (SMTPS), every other port must upgrade with STARTTLS (and the sender must refuse
 * to continue when the server does not offer it). Task 18 (mail sending) reuses this.
 */
final class SmtpSecurity
{
    public const IMPLICIT_TLS = 'tls';
    public const STARTTLS = 'starttls';

    /** 'tls' for port 465, 'starttls' for everything else. */
    public static function smtpSecure(int $port): string
    {
        return $port === 465 ? self::IMPLICIT_TLS : self::STARTTLS;
    }
}
