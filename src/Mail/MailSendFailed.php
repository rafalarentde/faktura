<?php
declare(strict_types=1);

namespace App\Mail;

/**
 * A message could not be handed to the mail server. Carries ONLY a short reason code, never the
 * server's banner or response (those can contain addresses or hints about credentials).
 */
final class MailSendFailed extends \RuntimeException
{
    public const AUTH = 'auth';
    public const CONNECT = 'connect';
    public const TLS = 'tls';
    public const RECIPIENT = 'recipient';
    public const OTHER = 'other';
    public const REASONS = [self::AUTH, self::CONNECT, self::TLS, self::RECIPIENT, self::OTHER];

    public readonly string $reason;

    public function __construct(string $reason)
    {
        $this->reason = in_array($reason, self::REASONS, true) ? $reason : self::OTHER;
        parent::__construct('Mail could not be sent (' . $this->reason . ').');
    }

    /** Translation key of the user-facing text for this reason. */
    public function translationKey(): string
    {
        return 'mail.fail_' . $this->reason;
    }
}
