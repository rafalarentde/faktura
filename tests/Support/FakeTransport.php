<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Mail\MailSendFailed;
use App\Mail\MailTransport;

/** Records every send() call; never touches the network. Can be told to fail with a reason code. */
final class FakeTransport implements MailTransport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];
    public ?string $failWith = null;
    public ?\Throwable $throw = null;

    public function send(array $smtp, string $from, string $fromName, string $to, string $subject, string $body, array $attachments): void
    {
        $this->sent[] = compact('smtp', 'from', 'fromName', 'to', 'subject', 'body', 'attachments');
        if ($this->throw !== null) {
            throw $this->throw;
        }
        if ($this->failWith !== null) {
            throw new MailSendFailed($this->failWith);
        }
    }
}
