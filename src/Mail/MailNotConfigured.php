<?php
declare(strict_types=1);

namespace App\Mail;

/** The company has no complete SMTP settings (host, port, user, password, from address). Nothing was attempted. */
final class MailNotConfigured extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Mail sending is not configured for this company.');
    }
}
