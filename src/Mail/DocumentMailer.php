<?php
declare(strict_types=1);

namespace App\Mail;

use App\Auth\RateLimiter;
use App\Core\Crypto;
use App\Core\Audit;
use App\Core\Db;
use App\Domain\DocType;
use App\Domain\DocumentNotFound;
use App\Domain\ValidationException;
use App\Http\DocumentPresenter;
use App\I18n\Translator;
use App\Pdf\PdfNotAvailable;
use App\Pdf\PdfRenderer;
use App\Repo\CompanySettingsRepo;
use App\Repo\DocumentRepo;
use App\Repo\EmailLogRepo;
use App\Tenant\CompanyContext;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Sends ISSUED documents of the active company to ONE recipient with the company's own mailbox
 * (Task 18). Nothing is ever sent automatically: send() is called only from an explicit user
 * action; preview() has no side effects at all.
 *
 * Data: defaults (recipient, subject, body) come ONLY from the document's snapshots (never from
 * live customer or company rows); the one live input is the company's SMTP settings, whose
 * password is decrypted right before the transport call and handed over, never logged or stored.
 *
 * send() order: access (unknown/foreign/unsupported type => DocumentNotFound, draft =>
 * PdfNotAvailable) -> SMTP configured (MailNotConfigured; nothing attempted, nothing logged) ->
 * input (ValidationException for to/subject/body; header injection is rejected here and again in
 * the transport) -> rate limit (20 attempts per 10 minutes per company; a refused call neither
 * sends nor logs) -> PDF -> transport. The attempt is then ALWAYS written to email_log (sent or
 * failed with a reason code) and audit_log (ids/status/reason code only, never recipient,
 * subject, body or credentials); a failure is rethrown as MailSendFailed AFTER logging.
 *
 * Supported types are TYPES; for another type add `templates/mail/<type>.<lang>.twig` (de, pl, en),
 * the `mail.subject_<type>` keys and the type here (and PDF support in PdfRenderer). Offers: TYPES includes them; after a delivered mail
 * the optional $offerSent callback marks an open offer as sent (after the log rows, errors swallowed).
 * Dunning letters (Task 21): mailed like any issued document (explicit POST only); sending changes
 * nothing else (no status of the letter or the invoice).
 */
final class DocumentMailer
{
    public const TYPES = [DocType::INVOICE, DocType::CREDIT_NOTE, DocType::OFFER, DocType::DUNNING];
    public const LIMIT = 20;
    public const TEST_LIMIT = 3;
    public const WINDOW_SEC = 600;
    public const SUBJECT_MAX = 200;
    public const BODY_MAX = 10000;
    public const ADDRESS_MAX = 254;
    public const LOCAL_MAX = 64;

    private CompanySettingsRepo $settings;
    private EmailLogRepo $log;
    private Environment $text;
    private \Closure $clock;
    private ?Audit $audit = null;

    /**
     * @param string $templateDir directory with the <type>.<lang>.twig plain-text bodies
     * @param \Closure|null $offerSent fn(int $offerId): void, called after an offer mail was DELIVERED
     *        (App wires DocumentService::markOfferSent: an open offer becomes "sent")
     */
    public function __construct(
        private Db $db,
        private CompanyContext $ctx,
        private DocumentRepo $docs,
        private DocumentPresenter $presenter,
        private PdfRenderer $pdf,
        private MailTransport $transport,
        private Crypto $crypto,
        private Translator $translator,
        \Closure $clock,
        string $templateDir,
        private ?SmtpTargetPolicy $policy = null,
        private ?\Closure $offerSent = null,
    ) {
        $this->clock = $clock;
        $this->settings = new CompanySettingsRepo($db, $ctx, $crypto, $clock);
        $this->log = new EmailLogRepo($db, $ctx);
        // Plain text for a plain-text mail: autoescape would turn "&" into "&amp;". Never used as HTML.
        $this->text = new Environment(new FilesystemLoader($templateDir), ['autoescape' => false, 'strict_variables' => true]);
    }

    /**
     * Defaults for the send form. No side effects: nothing is sent, logged or rate limited, and the
     * PDF is not rendered.
     *
     * @return array{to: string, subject: string, body: string, attachment_name: string, lang: string, configured: bool}
     * @throws DocumentNotFound unknown id, another company's document, unsupported type
     * @throws PdfNotAvailable the document is a draft
     */
    public function preview(int $docId): array
    {
        $doc = $this->issued($docId);
        $vm = $this->presenter->issued($doc);
        $lang = (string) $vm['lang'];
        $type = (string) $doc['type'];
        $company = self::line((string) $vm['seller']['name']);
        $number = self::line((string) $doc['number']);
        $dunning = $type === DocType::DUNNING;
        $payment = $type === DocType::INVOICE || $dunning; // documents that ask for a transfer carry the bank data
        $level = $dunning ? self::line((string) $vm['title']) : '';
        $invoice = $dunning ? self::line((string) $vm['invoice_number']) : '';
        $body = $this->text->render($type . '.' . $lang . '.twig', [
            'buyer' => self::line((string) $vm['buyer']['name']),
            'number' => $number,
            'gross' => (string) $vm['gross'],
            'due' => $type === DocType::INVOICE ? (string) ($vm['due_date'] ?? '') : '',
            'iban' => $payment ? self::line((string) $vm['seller']['iban']) : '',
            'bic' => $payment ? self::line((string) $vm['seller']['bic']) : '',
            'related' => self::line((string) ($vm['related'] ?? '')),
            'valid_until' => $type === DocType::OFFER ? self::line((string) ($vm['valid_until'] ?? '')) : '',
            'company' => $company,
            // dunning letters (Task 21): the level wording, the dunned invoice and the pay-by date
            'level' => $level,
            'invoice' => $invoice,
            'invoice_date' => $dunning ? self::line((string) $vm['invoice_date']) : '',
            'pay_by' => $dunning ? self::line((string) $vm['pay_by']) : '',
        ]);
        $body = trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $body))) . "\n";
        $buyerEmail = self::asciiDomain((string) (((array) ($doc['buyer'] ?? []))['email'] ?? ''));
        return [
            'to' => self::normalizeRecipient($buyerEmail) ?? '',
            'subject' => mb_substr($this->translator->t('mail.subject_' . $type, $lang, ['number' => $number, 'company' => $company, 'level' => $level, 'invoice' => $invoice]), 0, self::SUBJECT_MAX),
            'body' => $body,
            'attachment_name' => PdfRenderer::filename((string) $doc['number']),
            'lang' => $lang,
            'configured' => $this->settings->mailConfigured(),
        ];
    }

    /**
     * Sends the document's PDF to $to. See the class comment for the order of the checks.
     *
     * @throws DocumentNotFound|PdfNotAvailable|MailNotConfigured
     * @throws ValidationException keys to | subject | body (422), rate (too many sends, 429)
     * @throws MailSendFailed the attempt failed; already logged (reason code only)
     */
    public function send(int $docId, string $to, string $subject, string $body): void
    {
        $doc = $this->issued($docId);
        if (!$this->settings->mailConfigured()) {
            throw new MailNotConfigured();
        }
        [$to, $subject, $body] = $this->validated($to, $subject, $body);
        $this->throttle('mail:company:' . $this->ctx->id(), self::LIMIT);

        $attachment = $this->pdf->renderFile($docId);
        $creds = $this->settings->smtpCredentials() ?? throw new MailNotConfigured();

        $reason = null;
        try {
            $this->deliver($creds, $to, $subject, $body, [['name' => $attachment['filename'], 'content' => $attachment['content'], 'mime' => 'application/pdf']]);
        } catch (MailSendFailed $e) {
            $reason = $e->reason;
        }
        $status = $reason === null ? 'sent' : 'failed';
        $at = ($this->clock)();
        $detail = ['document_id' => (int) $doc['id'], 'status' => $status] + ($reason === null ? [] : ['reason' => $reason]);
        $this->db->transaction(function () use ($doc, $to, $subject, $status, $reason, $at, $detail): void {
            $this->log->record((int) $doc['id'], $to, $subject, $status, $reason, $this->ctx->userId(), $at);
            $this->audit('document.email_send', $detail, $at);
        });
        if ($reason === null && $doc['type'] === DocType::OFFER && $this->offerSent !== null) {
            // Bookkeeping only: a failure here must never turn a delivered mail into an error.
            try {
                ($this->offerSent)((int) $doc['id']);
            } catch (\Throwable) {
            }
        }
        if ($reason !== null) {
            throw new MailSendFailed($reason);
        }
    }

    /**
     * Sends a short test message (no attachment) with the saved settings. The caller passes the
     * CURRENT user's own login address, never an address from a form. 3 attempts per 10 minutes
     * per company; audited as settings.mail_test (status and reason code only).
     *
     * @throws MailNotConfigured
     * @throws ValidationException key to (bad address) or rate
     * @throws MailSendFailed
     */
    public function sendTest(string $to, string $lang): void
    {
        if (!$this->settings->mailConfigured()) {
            throw new MailNotConfigured();
        }
        $to = self::normalizeRecipient($to) ?? throw new ValidationException(['to' => 'mail.err_to']);
        $this->throttle('mailtest:company:' . $this->ctx->id(), self::TEST_LIMIT);
        $creds = $this->settings->smtpCredentials() ?? throw new MailNotConfigured();
        $company = self::line((string) ($this->ctx->company()['name'] ?? ''));
        $reason = null;
        try {
            $this->deliver(
                $creds, $to, $this->translator->t('mail.test_subject', $lang),
                $this->translator->t('mail.test_body', $lang, ['company' => $company]) . "\n", []
            );
        } catch (MailSendFailed $e) {
            $reason = $e->reason;
        }
        $this->audit('settings.mail_test', ['status' => $reason === null ? 'sent' : 'failed'] + ($reason === null ? [] : ['reason' => $reason]), ($this->clock)());
        if ($reason !== null) {
            throw new MailSendFailed($reason);
        }
    }

    /**
     * The one recipient address, or null when it is not exactly one plain ASCII address: no list
     * (comma, semicolon, space), no display name, no control characters, no IDN/unicode (enter the
     * punycode form), at most 254 characters and 64 before the @. Surrounding spaces are removed.
     */
    public static function normalizeRecipient(string $to): ?string
    {
        $to = trim($to, ' ');
        if ($to === '' || strlen($to) > self::ADDRESS_MAX || preg_match('/[^\x21-\x7E]/', $to) === 1 || preg_match('/[,;<>"()\[\]\\\\:]/', $to) === 1) {
            return null;
        }
        $parts = explode('@', $to);
        if (count($parts) !== 2 || $parts[0] === '' || strlen($parts[0]) > self::LOCAL_MAX || $parts[1] === '') {
            return null;
        }
        return filter_var($to, FILTER_VALIDATE_EMAIL) === false ? null : $to;
    }

    /**
     * Prefill convenience: converts a non-ASCII DOMAIN to punycode (x@bücher.de -> x@xn--bcher-kva.de)
     * when intl is available. A non-ASCII local part is left alone (and refused by the validation).
     */
    private static function asciiDomain(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || !function_exists('idn_to_ascii') || preg_match('/[^\x00-\x7F]/', substr($email, $at + 1)) !== 1) {
            return $email;
        }
        $domain = idn_to_ascii(substr($email, $at + 1), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        return $domain === false ? $email : substr($email, 0, $at + 1) . $domain;
    }

    /** @return array<string, mixed> @throws DocumentNotFound|PdfNotAvailable */
    private function issued(int $docId): array
    {
        $doc = $this->docs->find($docId);
        if ($doc === null || !in_array($doc['type'], self::TYPES, true)) {
            throw new DocumentNotFound();
        }
        if ($doc['status'] !== 'issued') {
            throw new PdfNotAvailable();
        }
        return $doc;
    }

    /**
     * @return array{0: string, 1: string, 2: string} recipient, subject, body (line breaks as LF)
     * @throws ValidationException
     */
    private function validated(string $to, string $subject, string $body): array
    {
        $errors = [];
        $recipient = self::normalizeRecipient($to);
        if ($recipient === null) {
            $errors['to'] = 'mail.err_to';
        }
        $subject = trim($subject, ' ');
        $length = mb_check_encoding($subject, 'UTF-8') ? mb_strlen($subject, 'UTF-8') : 0;
        if ($length < 1 || $length > self::SUBJECT_MAX || preg_match('/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{2028}\x{2029}]/u', $subject) === 1) {
            $errors['subject'] = 'mail.err_subject';
        }
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $length = mb_check_encoding($body, 'UTF-8') ? mb_strlen($body, 'UTF-8') : 0;
        if ($length < 1 || $length > self::BODY_MAX || trim($body) === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body) === 1) {
            $errors['body'] = 'mail.err_body';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [(string) $recipient, $subject, $body];
    }

    /** Counts this attempt; refuses (nothing happens) when the company is over its limit. @throws ValidationException */
    private function throttle(string $key, int $max): void
    {
        $limiter = new RateLimiter($this->db, $max, self::WINDOW_SEC, $this->clock);
        // Hit first (RateLimiter::attempt): concurrent sends all record their hit before any is checked.
        if (!$limiter->attempt($key)) {
            throw new ValidationException(['rate' => 'mail.err_rate']);
        }
    }

    /**
     * Hands the message to the transport with the decrypted credentials. Anything but a
     * MailSendFailed is reduced to reason 'other' (its text may contain secrets).
     *
     * @param array{host: string, port: int, user: string, pass: string, from_addr: string, from_name: string} $creds
     * @param list<array{name: string, content: string, mime: string}> $attachments
     * @throws MailSendFailed
     */
    private function deliver(array $creds, string $to, string $subject, string $body, array $attachments): void
    {
        // SSRF guard: refused before the transport is touched (the transport checks again).
        if ($this->policy !== null && (!SmtpTargetPolicy::portAllowed($creds['port']) || $this->policy->connectAddress($creds['host']) === null)) {
            throw new MailSendFailed(MailSendFailed::CONNECT);
        }
        $secure = SmtpSecurity::smtpSecure($creds['port']) === SmtpSecurity::IMPLICIT_TLS ? 'ssl' : 'tls';
        $smtp = ['host' => $creds['host'], 'port' => $creds['port'], 'user' => $creds['user'], 'pass' => $creds['pass'], 'secure' => $secure];
        try {
            $this->transport->send($smtp, $creds['from_addr'], self::line($creds['from_name']), $to, $subject, $body, $attachments);
        } catch (MailSendFailed $e) {
            throw new MailSendFailed($e->reason);
        } catch (\Throwable) {
            throw new MailSendFailed(MailSendFailed::OTHER);
        }
    }

    /** @param array<string, mixed> $detail */
    private function audit(string $action, array $detail, int $at): void
    {
        $this->audit ??= new Audit($this->db, $this->clock);
        $this->audit->log($action, $detail, $this->ctx->id(), $this->ctx->userId(), $at);
    }

    /** Single-line plain string: control characters and line separators become spaces. */
    private static function line(string $s): string
    {
        $s = mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        return trim((string) preg_replace('/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{2028}\x{2029}]+/u', ' ', $s));
    }
}
