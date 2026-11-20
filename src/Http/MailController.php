<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Domain\DocumentNotFound;
use App\Domain\ValidationException;
use App\Mail\DocumentMailer;
use App\Mail\MailNotConfigured;
use App\Mail\MailSendFailed;
use App\Money\Formatter;
use App\Pdf\PdfNotAvailable;
use App\Repo\DocumentRepo;
use App\Repo\EmailLogRepo;

/**
 * Sending an issued document by email (Task 18).
 *
 * - GET /documents/{id}/send: the preview/edit form (To, Subject, text, attachment name and PDF
 *   link, SMTP status, earlier sends). It has NO side effects: nothing is sent, logged or counted.
 * - POST /documents/{id}/send: the user's explicit click is the ONLY way an email goes out. CSRF
 *   is checked centrally. Success: 303 to the document with a flash; invalid input: 422 with the
 *   typed values; no SMTP settings: 409; rate limit: 429; the server refused: 502 with a mapped,
 *   secret-free text (never the server's own words).
 *
 * Unknown ids, other companies' documents and types without mail support give the same 404 page;
 * drafts give 409. Every role of the company may send (settings stay owner-only).
 */
final class MailController
{
    public function __construct(private App $app)
    {
    }

    /** @param array<string, string> $params */
    public function form(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        try {
            $preview = $this->app->mailer()->preview($id);
        } catch (DocumentNotFound) {
            return $this->error(404, 'error.not_found');
        } catch (PdfNotAvailable) {
            return $this->error(409, 'mail.err_draft');
        }
        return $this->page($id, $preview, ['to' => $preview['to'], 'subject' => $preview['subject'], 'body' => $preview['body']], [], null, 200, $this->takeFlash());
    }

    /** @param array<string, string> $params */
    public function send(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $typed = ['to' => $request->input('to'), 'subject' => $request->input('subject'), 'body' => $request->input('body')];
        $mailer = $this->app->mailer();
        try {
            $preview = $mailer->preview($id);
            $mailer->send($id, $typed['to'], $typed['subject'], $typed['body']);
        } catch (DocumentNotFound) {
            return $this->error(404, 'error.not_found');
        } catch (PdfNotAvailable) {
            return $this->error(409, 'mail.err_draft');
        } catch (MailNotConfigured) {
            return $this->page($id, $preview, $typed, [], 'mail.err_not_configured', 409);
        } catch (ValidationException $e) {
            $rate = isset($e->errors['rate']);
            return $this->page($id, $preview, $typed, array_diff_key($e->errors, ['rate' => 1]), $rate ? $e->errors['rate'] : null, $rate ? 429 : 422);
        } catch (MailSendFailed $e) {
            return $this->page($id, $preview, $typed, [], $e->translationKey(), 502);
        }
        $to = DocumentMailer::normalizeRecipient($typed['to']) ?? '';
        $this->app->session()->set(InvoiceController::FLASH, ['text' => $this->app->t('mail.sent_to', ['to' => $to]), 'kind' => 'notice']);
        return Response::redirect('/invoices/' . $id, 303);
    }

    /**
     * Log rows for the templates: formatted date, recipient (escaped by Twig), status and the
     * translation key of the reason on failures (a code outside the known list shows as 'other').
     *
     * @param list<array<string, mixed>> $rows EmailLogRepo::forDocument()
     * @return list<array{date: string, to: string, ok: bool, reason: string|null}>
     */
    public static function logRows(array $rows, string $lang): array
    {
        $fmt = new Formatter();
        $out = [];
        foreach ($rows as $r) {
            $at = (new \DateTimeImmutable((string) $r['created_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'));
            $ok = $r['status'] === 'sent';
            $code = in_array($r['error'], MailSendFailed::REASONS, true) ? (string) $r['error'] : MailSendFailed::OTHER;
            $out[] = ['date' => $fmt->date($at, $lang) . ' ' . $at->format('H:i'), 'to' => (string) $r['to_email'], 'ok' => $ok, 'reason' => $ok ? null : 'mail.fail_' . $code];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $preview DocumentMailer::preview()
     * @param array{to: string, subject: string, body: string} $form typed (or default) values
     * @param array<string, string> $errors field => translation key
     * @param array{text: string, kind: string}|null $flash
     */
    private function page(int $id, array $preview, array $form, array $errors, ?string $problem, int $status, ?array $flash = null): Response
    {
        $ctx = $this->app->context() ?? throw new \LogicException('RequireCompany must run first.');
        $doc = (new DocumentRepo($this->app->db(), $ctx))->find($id) ?? throw new DocumentNotFound();
        $log = new EmailLogRepo($this->app->db(), $ctx);
        return $this->app->render('documents/send.twig', [
            'doc' => ['id' => $id, 'number' => (string) $doc['number'], 'type' => (string) $doc['type'], 'buyer' => (string) ($doc['buyer_name'] ?? '')],
            'form' => $form,
            'errors' => array_map(fn (string $k): string => $this->app->t($k), $errors),
            'problem' => $problem === null ? null : $this->app->t($problem),
            'attachment_name' => (string) $preview['attachment_name'],
            'configured' => (bool) $preview['configured'],
            'is_owner' => $ctx->role() === 'owner',
            'no_recipient' => $preview['to'] === '',
            'log' => self::logRows($log->forDocument($id), $this->app->lang()),
            'flash' => $flash,
        ], $status);
    }

    /** @return array{text: string, kind: string}|null shown once (the issue flash of "Issue and prepare email") */
    private function takeFlash(): ?array
    {
        $f = $this->app->session()->get(InvoiceController::FLASH);
        $this->app->session()->remove(InvoiceController::FLASH);
        return is_array($f) && is_string($f['text'] ?? null) && in_array($f['kind'] ?? null, ['notice', 'warn'], true) ? $f : null;
    }

    private function error(int $status, string $key): Response
    {
        return $this->app->render('error.twig', ['status' => $status, 'message' => $this->app->t($key), 'detail' => null], $status);
    }
}
