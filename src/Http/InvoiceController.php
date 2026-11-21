<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Request;
use App\Core\Response;
use App\Domain\DocType;
use App\Domain\ValidationException;
use App\Money\AmountParser;
use App\Repo\DocumentRepo;
use App\Repo\EmailLogRepo;

/**
 * Invoices and credit notes (Task 16): list, the read-only view of issued documents with payments
 * and corrections. The editor, issue, delete and the live totals JSON are shared with the offers
 * (AbstractDocumentController).
 *
 * Every /invoices/{id} route answers 404 for offers and dunning letters, exactly like an unknown
 * id: load() only returns invoices and credit notes. New drafts are invoices; credit notes are
 * created by POST /invoices/{id}/correct.
 */
final class InvoiceController extends AbstractDocumentController
{
    private const LIST_TYPES = [DocType::INVOICE, DocType::CREDIT_NOTE];
    private const STATUS_FILTERS = ['draft', 'open', 'partial', 'overdue', 'paid', 'credited', 'issued'];

    protected function docType(): string
    {
        return DocType::INVOICE;
    }

    protected function loadTypes(): array
    {
        return self::LIST_TYPES;
    }

    protected function prefix(): string
    {
        return '/invoices';
    }

    protected function defaultDates(array $customer, \DateTimeImmutable $today): array
    {
        $days = max(0, (int) $customer['payment_days']);
        return ['issue_date' => $today->format('Y-m-d'), 'service_date' => $today->format('Y-m-d'),
            'due_date' => $today->modify("+{$days} days")->format('Y-m-d')];
    }

    protected function viewIssued(array $doc): Response
    {
        return $this->view($doc);
    }

    // ---- list ----

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $query = ListQuery::fromRequest($request, self::STATUS_FILTERS);
        [$q, $status] = [$query->q, $query->status];
        $type = in_array($request->queryParam('type'), self::LIST_TYPES, true) ? $request->queryParam('type') : '';

        $filter = ['q' => $q];
        $filter += $type === '' ? ['types' => self::LIST_TYPES] : ['type' => $type];
        $today = $this->now();
        $service = $this->service();
        if (in_array($status, DocumentRepo::PAYMENT_STATUSES, true)) {
            // R27: payment states of issued invoices from the ONE aggregate query (same semantics as
            // paymentSummary). Only invoices have payment states (a type=credit_note filter gives no rows).
            $filterP = ['status' => $status, 'q' => $q];
            $total = $type === DocType::CREDIT_NOTE ? 0 : $this->docs()->paymentCount($filterP, $today);
            $page = $query->clampedPage($total);
            $slice = $total === 0 ? [] : array_map(
                fn (array $r): array => [$r, ['status' => $r['pay_status']]],
                $this->docs()->paymentRows($filterP, $today, ListQuery::PER_PAGE, ($page - 1) * ListQuery::PER_PAGE, 'issued')
            );
        } else {
            if ($status !== '') {
                $filter['status'] = $status === 'draft' ? 'draft' : 'issued';
            }
            $total = $this->docs()->count($filter);
            $page = $query->clampedPage($total);
            $slice = array_map(
                fn (array $d): array => [$d, $service->paymentSummary((int) $d['id'], $today)],
                $this->docs()->list($filter, ListQuery::PER_PAGE, ($page - 1) * ListQuery::PER_PAGE)
            );
        }

        $rows = [];
        foreach ($slice as [$doc, $summary]) {
            $rows[] = [
                'id' => (int) $doc['id'],
                'number' => $doc['number'],
                'buyer' => (string) ($doc['buyer_name'] ?? ''),
                'type' => (string) $doc['type'],
                'issue_date' => $this->date($doc['issue_date']),
                'due_date' => $doc['type'] === DocType::INVOICE ? $this->date($doc['due_date']) : null,
                'amount' => $this->money((int) $doc['gross_minor'], (string) $doc['currency']),
                'status' => $summary['status'],
                'chip' => StatusChips::invoice($summary['status']),
                'reverse_charge' => (int) $doc['reverse_charge'] === 1,
                'currency' => (string) $doc['currency'],
            ];
        }
        $pager = $query->pager('/invoices', ['q' => $q, 'status' => $status, 'type' => $type], $total);
        return $this->app->render('invoices/list.twig', [
            'rows' => $rows,
            'q' => $q,
            'status' => $status,
            'type' => $type,
            'statuses' => self::STATUS_FILTERS,
            'types' => self::LIST_TYPES,
        ] + $pager + [
            'total' => $total,
            'flash' => $this->takeFlash(),
        ]);
    }

    /** @param array<string, string> $params */
    public function payments(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $doc = $this->load($id);
        if ($doc === null) {
            return $this->notFound();
        }
        if ($doc['type'] !== DocType::INVOICE || $doc['status'] !== 'issued') {
            $this->flash('document.err_payment_not_allowed', [], 'warn');
            return Response::redirect('/invoices/' . $id, 303);
        }
        $typed = ['amount' => $request->input('amount'), 'paid_on' => $request->input('paid_on'), 'note' => $request->input('note')];
        $errors = [];
        try {
            $amount = AmountParser::parse($typed['amount'], $this->app->lang());
        } catch (\InvalidArgumentException) {
            $errors['amount'] = 'document.err_amount';
            $amount = 0;
        }
        $paidOn = self::realDate($typed['paid_on']);
        if ($paidOn === null) {
            $errors['paid_on'] = 'document.err_date';
        }
        if ($errors === []) {
            try {
                $this->service()->addPayment($id, $amount, new \DateTimeImmutable($paidOn . ' 12:00:00', new \DateTimeZone('Europe/Berlin')), $typed['note']);
                $this->flash('invoice.payment_added');
                return Response::redirect('/invoices/' . $id, 303);
            } catch (ValidationException $e) {
                $errors = $e->errors;
            }
        }
        return $this->view($doc, $typed, $errors, 422);
    }

    /** @param array<string, string> $params */
    public function correct(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $doc = $this->load($id);
        if ($doc === null) {
            return $this->notFound();
        }
        if (!$this->canCorrect($doc)) {
            $this->flash('document.err_correction_not_allowed', [], 'warn');
            return Response::redirect('/invoices/' . $id, 303);
        }
        try {
            $cn = $this->service()->createCorrection($id);
        } catch (ValidationException) {
            $this->flash('document.err_correction_not_allowed', [], 'warn');
            return Response::redirect('/invoices/' . $id, 303);
        }
        $this->flash('invoice.correction_created');
        return Response::redirect('/invoices/' . $cn, 303);
    }

    // ---- issued view ----

    /**
     * @param array<string, mixed> $doc issued document (DocumentRepo::find)
     * @param array<string, string>|null $payment typed payment form values after an error
     * @param array<string, string> $errors
     */
    private function view(array $doc, ?array $payment = null, array $errors = [], int $status = 200): Response
    {
        $id = (int) $doc['id'];
        $lang = $this->app->lang();
        $now = $this->now();
        $summary = $this->service()->paymentSummary($id, $now);
        $currency = (string) $doc['currency'];
        $isInvoice = $doc['type'] === DocType::INVOICE;
        $payments = [];
        foreach ($this->docs()->payments($id) as $p) {
            $payments[] = ['date' => $this->date($p['paid_on']), 'amount' => $this->money((int) $p['amount_minor'], $currency), 'note' => (string) ($p['note'] ?? '')];
        }
        $creditNotes = [];
        if ($isInvoice) {
            foreach ($this->docs()->list(['type' => DocType::CREDIT_NOTE, 'related_document_id' => $id]) as $cn) {
                $creditNotes[] = ['id' => (int) $cn['id'], 'number' => $cn['number'], 'date' => $this->date($cn['issue_date']),
                    'amount' => $this->money((int) $cn['gross_minor'], $currency), 'status' => (string) $cn['status']];
            }
        }
        $related = null;
        $relatedOffer = null;
        if ($doc['related_document_id'] !== null) {
            $rel = $this->docs()->row((int) $doc['related_document_id']);
            if ($rel !== null && !$isInvoice) {
                $related = ['id' => (int) $rel['id'], 'number' => (string) $rel['number'], 'date' => $this->date($rel['issue_date'])];
            } elseif ($rel !== null && $rel['type'] === DocType::OFFER) {
                // an invoice created from an accepted offer links back to it
                $relatedOffer = ['id' => (int) $rel['id'], 'number' => (string) $rel['number'], 'date' => $this->date($rel['issue_date'])];
            }
        }
        $mailLog = new EmailLogRepo($this->app->db(), $this->ctx());
        return $this->app->render('invoices/show.twig', [
            'mail_log' => MailController::logRows($mailLog->forDocument($id), $lang),
            'mail_sent' => $mailLog->hasSent($id),
            'doc' => [
                'id' => $id, 'type' => (string) $doc['type'], 'number' => (string) $doc['number'],
                'issue_date' => $this->date($doc['issue_date']), 'service_date' => $this->date($doc['service_date']),
                'due_date' => $isInvoice ? $this->date($doc['due_date']) : null, 'currency' => $currency,
                'notes' => (string) ($doc['notes'] ?? ''), 'buyer' => (string) ($doc['buyer_name'] ?? ''),
            ],
            'prefix' => '/invoices',
            'type_label' => $this->app->t('invoice.type_' . $doc['type']),
            'status' => $summary['status'],
            'status_label' => $this->app->t('invoice.status_' . $summary['status']),
            'chip' => StatusChips::invoice($summary['status']),
            'summary' => $isInvoice ? [
                'gross' => $this->money($summary['gross_minor'], $currency), 'paid' => $this->money($summary['paid_minor'], $currency),
                'credited' => $this->money($summary['credited_minor'], $currency), 'balance' => $this->money($summary['balance_minor'], $currency),
            ] : null,
            'payments' => $payments,
            'credit_notes' => $creditNotes,
            'related' => $related,
            'related_offer' => $relatedOffer,
            'paper' => $this->presenter()->issued($doc),
            'can_pay' => $isInvoice && $summary['balance_minor'] > 0 && $summary['status'] !== 'credited',
            'can_correct' => $this->canCorrect($doc, $summary),
            'payment' => $payment ?? ['amount' => '', 'paid_on' => $now->format('Y-m-d'), 'note' => ''],
            'errors' => array_map(fn (string $k): string => $this->app->t($k), $errors),
            'flash' => $this->takeFlash(),
        ], $status);
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, mixed>|null $summary
     */
    private function canCorrect(array $doc, ?array $summary = null): bool
    {
        if ($doc['type'] !== DocType::INVOICE || $doc['status'] !== 'issued') {
            return false;
        }
        $summary ??= $this->service()->paymentSummary((int) $doc['id'], $this->now());
        return $summary['status'] !== 'credited';
    }
}
