<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Request;
use App\Core\Response;
use App\Domain\DocType;
use App\Domain\DocumentNotFound;
use App\Domain\DocumentService;
use App\Domain\ValidationException;
use App\Repo\EmailLogRepo;

/**
 * Offers (Task 19): list, status changes and conversion to an invoice draft. The editor (drafts),
 * save, issue, delete and the live totals are the shared behaviour of AbstractDocumentController;
 * offers differ only by a "valid until" date instead of service/due date, no VAT confirmation and
 * no payments or corrections.
 *
 * - load() serves only offers: an invoice id on an /offers route is the same 404 as an unknown id.
 * - The status (open, sent, accepted, rejected) is set by POST /offers/{id}/status from a fixed list
 *   of values; 'expired' is computed from valid_until and never posted or stored.
 * - POST /offers/{id}/convert creates (or returns the existing) DRAFT invoice of an accepted offer
 *   and redirects to its editor. The invoice draft links back to the offer (related_document_id).
 * - Issued offers are immutable: only offer_status changes (DocumentService::setOfferStatus).
 */
final class OfferController extends AbstractDocumentController
{
    /** Filter values of the list: the visible statuses (expired is computed from valid_until). */
    private const STATUS_FILTERS = ['draft', 'open', 'sent', 'accepted', 'rejected', 'expired'];
    private const CHIPS = ['draft' => 'chip-draft', 'open' => 'chip-sent', 'sent' => 'chip-sent', 'accepted' => 'chip-ok',
        'rejected' => 'chip-late', 'expired' => 'chip-soon'];
    /** Status each action button posts. */
    private const ACTIONS = ['sent' => 'offer.mark_sent', 'accepted' => 'offer.mark_accepted', 'rejected' => 'offer.mark_rejected', 'open' => 'offer.reopen'];

    protected function docType(): string
    {
        return DocType::OFFER;
    }

    protected function loadTypes(): array
    {
        return [DocType::OFFER];
    }

    protected function prefix(): string
    {
        return '/offers';
    }

    protected function defaultDates(array $customer, \DateTimeImmutable $today): array
    {
        return ['issue_date' => $today->format('Y-m-d'), 'valid_until' => $today->modify('+30 days')->format('Y-m-d')];
    }

    // ---- list ----

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $query = ListQuery::fromRequest($request, self::STATUS_FILTERS);
        $today = $this->now();
        $day = $today->format('Y-m-d');
        $filter = ['type' => DocType::OFFER, 'q' => $query->q] + match ($query->status) {
            '' => [],
            'draft' => ['status' => 'draft'],
            'expired' => ['status' => 'issued', 'offer_statuses' => ['open', 'sent'], 'valid_until_before' => $day],
            'open', 'sent' => ['status' => 'issued', 'offer_statuses' => [$query->status], 'valid_until_from' => $day],
            default => ['status' => 'issued', 'offer_statuses' => [$query->status]],
        };
        $total = $this->docs()->count($filter);
        $page = $query->clampedPage($total);
        $docs = $this->docs()->list($filter, ListQuery::PER_PAGE, ($page - 1) * ListQuery::PER_PAGE);
        $invoiced = $this->invoicedCounts(array_map(fn (array $d): int => (int) $d['id'], $docs));

        $service = $this->service();
        $rows = [];
        foreach ($docs as $doc) {
            $status = $service->offerStatus($doc, $today);
            $id = (int) $doc['id'];
            $rows[] = [
                'id' => $id,
                'number' => $doc['number'],
                'buyer' => (string) ($doc['buyer_name'] ?? ''),
                'valid_until' => $this->date($doc['valid_until']),
                'amount' => $this->money((int) $doc['gross_minor'], (string) $doc['currency']),
                'status' => $status,
                'chip' => self::CHIPS[$status],
                'invoiced' => ($invoiced[$id] ?? 0) > 0,
                'can_accept' => in_array($status, ['open', 'sent', 'expired'], true),
                'can_convert' => $status === 'accepted',
                'reverse_charge' => (int) $doc['reverse_charge'] === 1,
                'currency' => (string) $doc['currency'],
            ];
        }
        return $this->app->render('offers/list.twig', [
            'rows' => $rows,
            'q' => $query->q,
            'status' => $query->status,
            'statuses' => self::STATUS_FILTERS,
            'total' => $total,
            'flash' => $this->takeFlash(),
        ] + $query->pager('/offers', ['q' => $query->q, 'status' => $query->status], $total));
    }

    // ---- issued view ----

    protected function viewIssued(array $doc): Response
    {
        $id = (int) $doc['id'];
        $now = $this->now();
        $status = $this->service()->offerStatus($doc, $now);
        $currency = (string) $doc['currency'];
        $invoices = [];
        $issuedInvoices = 0;
        foreach ($this->docs()->list(['type' => DocType::INVOICE, 'related_document_id' => $id]) as $inv) {
            $issuedInvoices += $inv['status'] === 'issued' ? 1 : 0;
            $invoices[] = ['id' => (int) $inv['id'], 'number' => $inv['number'], 'date' => $this->date($inv['issue_date']),
                'amount' => $this->money((int) $inv['gross_minor'], (string) $inv['currency']), 'status' => (string) $inv['status']];
        }
        // Any stored status can become any other, except: an offer with a linked invoice stays accepted,
        // and an expired offer (computed) can only be decided (accepted/rejected), not reopened or marked sent.
        $stored = (string) ($doc['offer_status'] ?? 'open');
        $actions = [];
        foreach (self::ACTIONS as $target => $label) {
            $hidden = $target === $stored || ($status === 'expired' && in_array($target, ['open', 'sent'], true))
                || ($stored === 'accepted' && $invoices !== []);
            if (!$hidden) {
                $actions[] = ['status' => $target, 'label' => $this->app->t($label), 'primary' => $target === 'accepted'];
            }
        }
        $mailLog = new EmailLogRepo($this->app->db(), $this->ctx());
        return $this->app->render('offers/show.twig', [
            'mail_log' => MailController::logRows($mailLog->forDocument($id), $this->app->lang()),
            'mail_sent' => $mailLog->hasSent($id),
            'doc' => [
                'id' => $id, 'type' => DocType::OFFER, 'number' => (string) $doc['number'],
                'issue_date' => $this->date($doc['issue_date']), 'service_date' => null, 'due_date' => null,
                'valid_until' => $this->date($doc['valid_until']), 'currency' => $currency,
                'notes' => (string) ($doc['notes'] ?? ''), 'buyer' => (string) ($doc['buyer_name'] ?? ''),
            ],
            'prefix' => '/offers',
            'type_label' => $this->app->t('invoice.type_offer'),
            'status' => $status,
            'status_label' => $this->app->t('offer.status_' . $status),
            'chip' => self::CHIPS[$status],
            'actions' => $actions,
            'locked_accepted' => $invoices !== [] && $status === 'accepted',
            'can_convert' => $status === 'accepted',
            'already_invoiced' => $issuedInvoices > 0,
            'invoices' => $invoices,
            'summary' => null,
            'related' => null,
            'related_offer' => null,
            'paper' => $this->presenter()->issued($doc),
            'flash' => $this->takeFlash(),
            'errors' => [],
        ]);
    }

    // ---- actions ----

    /** @param array<string, string> $params */
    public function status(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if ($this->load($id) === null) {
            return $this->notFound();
        }
        try {
            $this->service()->setOfferStatus($id, $request->input('status'));
            $this->flash('offer.status_changed');
        } catch (DocumentNotFound) {
            return $this->notFound();
        } catch (ValidationException $e) {
            $this->flash((string) array_values($e->errors)[0], [], 'warn');
        }
        return Response::redirect($request->input('return') === 'list' ? '/offers' : '/offers/' . $id, 303);
    }

    /** @param array<string, string> $params */
    public function convert(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $offer = $this->load($id);
        if ($offer === null) {
            return $this->notFound();
        }
        try {
            $invoice = $this->service()->convertOffer($id);
        } catch (DocumentNotFound) {
            return $this->notFound();
        } catch (ValidationException $e) {
            $this->flash((string) array_values($e->errors)[0], [], 'warn');
            return Response::redirect($request->input('return') === 'list' ? '/offers' : '/offers/' . $id, 303);
        }
        $this->flash('offer.converted', ['number' => (string) $offer['number']]);
        return Response::redirect('/invoices/' . $invoice, 303);
    }

    // ---- helpers ----

    /**
     * Number of linked invoices (drafts and issued) per offer id, one query.
     *
     * @param list<int> $offerIds
     * @return array<int, int>
     */
    private function invoicedCounts(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }
        $counts = [];
        foreach ($this->docs()->list(['type' => DocType::INVOICE, 'related_document_ids' => $offerIds]) as $inv) {
            $rel = (int) $inv['related_document_id'];
            $counts[$rel] = ($counts[$rel] ?? 0) + 1;
        }
        return $counts;
    }
}
