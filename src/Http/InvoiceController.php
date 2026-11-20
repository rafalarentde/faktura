<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Domain\AccessSuspended;
use App\Domain\DocType;
use App\Domain\DocumentLocked;
use App\Domain\DocumentNotFound;
use App\Domain\DocumentService;
use App\Domain\Line;
use App\Domain\NumberSequences;
use App\Domain\Percent;
use App\Domain\Totals;
use App\Domain\Units;
use App\Domain\ValidationException;
use App\Domain\VatNeedsConfirmation;
use App\Domain\VatRule;
use App\Money\AmountParser;
use App\Money\Formatter;
use App\Money\Money;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Repo\ItemRepo;
use App\Tenant\CompanyContext;

/**
 * Invoices and credit notes (Task 16): list, editor (create mode and drafts), issue, the
 * read-only view of issued documents with payments and corrections, and the live totals JSON.
 *
 * Rules:
 * - Every route sits behind RequireCompany; ids come from route params only and every read goes
 *   through company-scoped repos, so another company's id (or an offer/dunning id: those have
 *   their own pages) gives the same 404 as an unknown id.
 * - Input is read through InvoiceForm (whitelist + the one parser for save, issue and recalc).
 *   The document type never comes from the form: new drafts are invoices, credit notes are
 *   created by POST /invoices/{id}/correct, everything else uses the loaded row.
 * - issue() of the service is called with NO transaction open and without reading the document
 *   in a surrounding transaction (DocumentService SNAPSHOTS); the draft is saved first (its own
 *   committed transaction), then issued at the app clock's Berlin "now".
 * - Issued documents are shown only from their stored snapshots (seller/buyer JSON, lines,
 *   totals); they have no edit/delete controls and write routes redirect back to the view.
 * - The editor works without JavaScript: add/remove line and "add from catalogue" are submit
 *   buttons that re-render the posted state without saving.
 */
final class InvoiceController
{
    private const PER_PAGE = 25;
    private const LIST_TYPES = [DocType::INVOICE, DocType::CREDIT_NOTE];
    private const PAYMENT_STATUSES = ['open', 'partial', 'overdue', 'paid', 'credited'];
    private const STATUS_FILTERS = ['draft', 'open', 'partial', 'overdue', 'paid', 'credited', 'issued'];
    private const CHIPS = ['draft' => 'chip-draft', 'open' => 'chip-sent', 'partial' => 'chip-soon', 'overdue' => 'chip-late',
        'paid' => 'chip-ok', 'credited' => 'chip-draft', 'issued' => 'chip-sent'];
    private const FLASH = 'invoice_flash';
    private const Q_MAX = 100;

    public function __construct(private App $app)
    {
    }

    // ---- list ----

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $q = mb_substr(trim($request->queryParam('q')), 0, self::Q_MAX);
        $status = in_array($request->queryParam('status'), self::STATUS_FILTERS, true) ? $request->queryParam('status') : '';
        $type = in_array($request->queryParam('type'), self::LIST_TYPES, true) ? $request->queryParam('type') : '';
        $page = ctype_digit($request->queryParam('page')) ? max(1, (int) $request->queryParam('page')) : 1;

        $filter = ['q' => $q];
        $filter += $type === '' ? ['types' => self::LIST_TYPES] : ['type' => $type];
        $today = $this->now();
        $service = $this->service();
        if (in_array($status, self::PAYMENT_STATUSES, true)) {
            // Payment states are computed per invoice (DocumentService::paymentSummary).
            $matching = [];
            // Only invoices have payment states (a type=credit_note filter gives no rows).
            $candidates = $type === DocType::CREDIT_NOTE ? [] : $this->docs()->list(['type' => DocType::INVOICE, 'status' => 'issued', 'q' => $q]);
            foreach ($candidates as $doc) {
                $summary = $service->paymentSummary((int) $doc['id'], $today);
                if ($summary['status'] === $status) {
                    $matching[] = [$doc, $summary];
                }
            }
            $total = count($matching);
            $pages = max(1, (int) ceil($total / self::PER_PAGE));
            $page = min($page, $pages);
            $slice = array_slice($matching, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        } else {
            if ($status !== '') {
                $filter['status'] = $status === 'draft' ? 'draft' : 'issued';
            }
            $total = $this->docs()->count($filter);
            $pages = max(1, (int) ceil($total / self::PER_PAGE));
            $page = min($page, $pages);
            $slice = array_map(
                fn (array $d): array => [$d, $service->paymentSummary((int) $d['id'], $today)],
                $this->docs()->list($filter, self::PER_PAGE, ($page - 1) * self::PER_PAGE)
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
                'chip' => self::CHIPS[$summary['status']] ?? 'chip-draft',
                'reverse_charge' => (int) $doc['reverse_charge'] === 1,
                'currency' => (string) $doc['currency'],
            ];
        }
        $base = array_filter(['q' => $q, 'status' => $status, 'type' => $type], fn (string $v): bool => $v !== '');
        $link = fn (int $p): string => '/invoices?' . http_build_query($base + ['page' => $p]);
        return $this->app->render('invoices/list.twig', [
            'rows' => $rows,
            'q' => $q,
            'status' => $status,
            'type' => $type,
            'statuses' => self::STATUS_FILTERS,
            'types' => self::LIST_TYPES,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'prev' => $page > 1 ? $link($page - 1) : null,
            'next' => $page < $pages ? $link($page + 1) : null,
            'filtered' => $base !== [],
            'flash' => $this->takeFlash(),
        ]);
    }

    // ---- create mode ----

    /** @param array<string, string> $params */
    public function newForm(Request $request, array $params): Response
    {
        $raw = $request->queryParam('customer_id');
        $customer = ctype_digit($raw) ? $this->customers()->find((int) $raw) : null;
        if ($customer === null) {
            return $this->app->render('invoices/new.twig', [
                'customers' => $this->customerChoices(),
                'error' => $raw !== '' ? $this->app->t('document.err_customer') : null,
            ]);
        }
        return $this->editor(null, $this->defaultsFor($customer));
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        $form = InvoiceForm::fromPost($request->post);
        $action = $this->rowAction($request, $form, null);
        if ($action !== null) {
            return $action;
        }
        $parsed = $form->parse($this->app->lang());
        if ($parsed['errors'] !== []) {
            return $this->editor(null, $form, $parsed['errors'], 422);
        }
        try {
            $id = $this->service()->createDraft(DocType::INVOICE, $parsed['data']);
        } catch (ValidationException $e) {
            return $this->editor(null, $form, InvoiceForm::remapErrors($e->errors, $parsed['rows']), 422);
        }
        if ($request->input('intent') === 'issue') {
            return $this->issueSaved($id, $request, $form);
        }
        $this->flash('invoice.saved');
        return Response::redirect('/invoices/' . $id, 303);
    }

    // ---- one document ----

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $doc = $this->load((int) $params['id']);
        if ($doc === null) {
            return $this->notFound();
        }
        if ($doc['status'] === 'draft') {
            return $this->editor($doc, InvoiceForm::fromDocument($doc, $this->app->lang()));
        }
        return $this->view($doc);
    }

    /** @param array<string, string> $params */
    public function save(Request $request, array $params): Response
    {
        $result = $this->saveDraft((int) $params['id'], $request);
        if ($result instanceof Response) {
            return $result;
        }
        $this->flash('invoice.saved');
        return Response::redirect('/invoices/' . $result, 303);
    }

    /** Saves the posted draft first (own transaction), then issues it. @param array<string, string> $params */
    public function issue(Request $request, array $params): Response
    {
        $result = $this->saveDraft((int) $params['id'], $request);
        if ($result instanceof Response) {
            return $result;
        }
        return $this->issueSaved($result, $request, InvoiceForm::fromPost($request->post));
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $doc = $this->load($id);
        if ($doc === null) {
            return $this->notFound();
        }
        try {
            $this->service()->deleteDraft($id);
        } catch (DocumentLocked) {
            return $this->locked($id);
        } catch (DocumentNotFound) {
            return $this->notFound();
        }
        $this->flash('invoice.deleted');
        return Response::redirect('/invoices', 303);
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

    /**
     * Live totals for the editor: the same fields as save, parsed by InvoiceForm, totals by
     * Totals::compute. Nothing is persisted. Optional `document_id` (a draft of this company)
     * supplies the credit-note context (fixed VAT treatment, currency, rate, negative sign).
     *
     * @param array<string, string> $params
     */
    public function recalc(Request $request, array $params): Response
    {
        $doc = null;
        $docId = $request->input('document_id');
        if ($docId !== '') {
            $doc = ctype_digit($docId) ? $this->load((int) $docId) : null;
            if ($doc === null) {
                return Response::json(['error' => $this->app->t('error.not_found')], 404);
            }
        }
        $form = InvoiceForm::fromPost($request->post);
        $creditNote = $doc !== null && $doc['type'] === DocType::CREDIT_NOTE;
        $parsed = $form->parse($this->app->lang(), $creditNote);
        $errors = $parsed['errors'];
        unset($errors['lines']);
        $totals = null;
        $rc = false;
        $currency = $creditNote ? (string) $doc['currency'] : $form->fields['currency'];
        if ($errors === [] && $parsed['data']['lines'] !== []) {
            $vat = $this->vatContext($doc, $form);
            if ($vat['error'] !== null) {
                $errors['customer_id'] = $vat['error'];
            } else {
                $rc = $vat['reverse_charge'];
                try {
                    $totals = InvoiceForm::totals($parsed['data']['lines'], $rc, $currency,
                        $creditNote ? ($doc['eur_rate'] === null ? null : (string) $doc['eur_rate']) : $form->rate());
                } catch (ValidationException $e) {
                    $errors = $e->errors;
                }
            }
        } elseif ($parsed['data']['lines'] === []) {
            $errors['lines'] = 'document.err_lines';
        }
        $messages = array_map(fn (string $k): string => $this->app->t($k), $errors);
        $lineNets = [];
        foreach ($form->lines as $row) {
            $net = InvoiceForm::lineNet($row, $this->app->lang());
            $lineNets[] = $net === null || !in_array($currency, DocumentService::CURRENCIES, true) ? '' : $this->money($net, $currency);
        }
        if ($totals === null) {
            return Response::json(['net' => null, 'vat' => [], 'gross' => null, 'vat_eur' => null, 'reverse_charge' => $rc,
                'errors' => $messages, 'formatted' => ['lines' => $lineNets]], 422);
        }
        $sign = $creditNote ? -1 : 1;
        $view = $this->totalsView($totals, $sign, $currency, $rc, $this->app->lang());
        $vat = [];
        foreach ($totals->vat as $bp => $amount) {
            $vat[] = ['rate' => $bp, 'amount' => $sign * $amount, 'formatted' => $this->money($sign * $amount, $currency)];
        }
        return Response::json([
            'net' => $sign * $totals->netMinor,
            'vat' => $vat,
            'gross' => $sign * $totals->grossMinor,
            'vat_eur' => $totals->vatEurMinor === null ? null : $sign * $totals->vatEurMinor,
            'reverse_charge' => $rc,
            'errors' => [],
            'formatted' => ['net' => $view['net'], 'gross' => $view['gross'], 'vat_eur' => $view['vat_eur'],
                'vat_rows' => $view['vat'], 'lines' => $lineNets],
        ]);
    }

    // ---- flows ----

    /**
     * Loads the draft and saves the posted form. Returns the id on success, else the response
     * to send (404, redirect for a locked document, row-action or 422 re-render).
     */
    private function saveDraft(int $id, Request $request): int|Response
    {
        $doc = $this->load($id);
        if ($doc === null) {
            return $this->notFound();
        }
        if ($doc['status'] !== 'draft') {
            return $this->locked($id);
        }
        $form = InvoiceForm::fromPost($request->post);
        $action = $this->rowAction($request, $form, $doc);
        if ($action !== null) {
            return $action;
        }
        $parsed = $form->parse($this->app->lang(), $doc['type'] === DocType::CREDIT_NOTE);
        if ($parsed['errors'] !== []) {
            return $this->editor($doc, $form, $parsed['errors'], 422);
        }
        try {
            $this->service()->updateDraft($id, $parsed['data']);
        } catch (ValidationException $e) {
            return $this->editor($doc, $form, InvoiceForm::remapErrors($e->errors, $parsed['rows']), 422);
        } catch (DocumentLocked) {
            return $this->locked($id);
        } catch (DocumentNotFound) {
            return $this->notFound();
        }
        return $id;
    }

    /** Issues a SAVED draft (no transaction open here). */
    private function issueSaved(int $id, Request $request, InvoiceForm $form): Response
    {
        $confirm = $request->input('confirm_vat') === '1';
        try {
            $number = $this->service()->issue($id, $this->now(), $confirm);
        } catch (VatNeedsConfirmation) {
            return $this->editorFor($id, $form, ['confirm_vat' => 'invoice.err_confirm_vat'], 422);
        } catch (AccessSuspended) {
            return $this->editorFor($id, $form, ['document' => 'invoice.suspended'], 403);
        } catch (ValidationException $e) {
            return $this->editorFor($id, $form, $e->errors, 422);
        } catch (DocumentLocked) {
            return $this->locked($id);
        } catch (DocumentNotFound) {
            return $this->notFound();
        }
        $this->flash('invoice.issued_as', ['number' => $number]);
        return Response::redirect('/invoices/' . $id, 303);
    }

    /** @param array<string, string> $errors */
    private function editorFor(int $id, InvoiceForm $form, array $errors, int $status): Response
    {
        $doc = $this->load($id);
        return $doc === null ? $this->notFound() : $this->editor($doc, $form, $errors, $status);
    }

    /**
     * Handles the no-JS row buttons (add line, remove line, add from catalogue): re-renders the
     * posted state without saving. Null when the request is a real save.
     *
     * @param array<string, mixed>|null $doc
     */
    private function rowAction(Request $request, InvoiceForm $form, ?array $doc): ?Response
    {
        $remove = $request->post['remove_line'] ?? null;
        if (is_array($remove) && $remove !== []) {
            $i = (string) array_key_first($remove);
            if (ctype_digit($i) && isset($form->lines[(int) $i])) {
                array_splice($form->lines, (int) $i, 1);
            }
            if ($form->lines === []) {
                $form->lines[] = InvoiceForm::blankLine();
            }
            return $this->editor($doc, $form);
        }
        if ($request->input('add_line') !== '') {
            if (count($form->lines) >= InvoiceForm::MAX_ROWS - 1) {
                return $this->editor($doc, $form, ['lines' => 'document.err_lines'], 422);
            }
            $form->lines[] = InvoiceForm::blankLine();
            return $this->editor($doc, $form);
        }
        if ($request->input('add_item') !== '') {
            $raw = $request->input('catalogue_item');
            $item = ctype_digit($raw) ? $this->items()->find((int) $raw) : null;
            if ($item === null || (int) $item['is_active'] !== 1) {
                return $this->editor($doc, $form, ['catalogue' => 'invoice.err_catalogue_item'], 422);
            }
            if (count($form->lines) >= InvoiceForm::MAX_ROWS - 1) {
                return $this->editor($doc, $form, ['lines' => 'document.err_lines'], 422);
            }
            $docLang = $doc !== null && $doc['type'] === DocType::CREDIT_NOTE ? (string) $doc['lang'] : $form->fields['lang'];
            $docLang = in_array($docLang, DocumentService::LANGS, true) ? $docLang : $this->app->lang();
            $currency = $doc !== null && $doc['type'] === DocType::CREDIT_NOTE ? (string) $doc['currency'] : $form->fields['currency'];
            $sameCurrency = (string) $item['currency'] === $currency;
            // Drop a trailing untouched blank row so the item takes its place.
            $last = end($form->lines);
            if ($last !== false && $last === InvoiceForm::blankLine() && count($form->lines) > 1) {
                array_pop($form->lines);
            }
            $form->lines[] = [
                'name' => $this->items()->nameFor($item, $docLang),
                'description' => (string) ($item['names'][$docLang]['description'] ?? ''),
                'qty' => '1', 'unit' => (string) $item['unit'],
                'price' => $sameCurrency ? (new Formatter())->decimalAmount((int) $item['price_minor'], $this->app->lang()) : '',
                'discount' => '', 'vat_bp' => (string) (int) $item['vat_bp'], 'item_id' => (string) (int) $item['id'],
            ];
            $notice = $sameCurrency ? null : $this->app->t('invoice.catalogue_currency', [
                'price' => $this->money((int) $item['price_minor'], (string) $item['currency']),
                'currency' => in_array($currency, DocumentService::CURRENCIES, true) ? $currency : '?',
            ]);
            return $this->editor($doc, $form, [], 200, $notice);
        }
        return null;
    }

    // ---- editor page ----

    /**
     * @param array<string, mixed>|null $doc the stored draft (null = create mode)
     * @param array<string, string> $errors field => translation key
     */
    private function editor(?array $doc, InvoiceForm $form, array $errors = [], int $status = 200, ?string $notice = null): Response
    {
        $lang = $this->app->lang();
        $creditNote = $doc !== null && $doc['type'] === DocType::CREDIT_NOTE;
        $fields = $form->fields;
        if ($creditNote) {
            foreach (InvoiceForm::FIXED_FOR_CREDIT_NOTE as $f) {
                $fields[$f] = $f === 'customer_id' ? (string) $doc['customer_id'] : (string) ($doc[$f] ?? '');
            }
        }
        $currency = in_array($fields['currency'], DocumentService::CURRENCIES, true) ? $fields['currency'] : 'EUR';
        $vat = $this->vatContext($doc, $form);
        $rc = $vat['reverse_charge'];
        $customer = ctype_digit($fields['customer_id']) ? $this->customers()->find((int) $fields['customer_id']) : null;

        $messages = array_map(fn (string $k): string => $this->app->t($k), $errors);
        $lines = [];
        foreach ($form->lines as $i => $row) {
            $net = InvoiceForm::lineNet($row, $lang);
            $e = fn (string $f): ?string => $messages["lines.$i.$f"] ?? null;
            $lines[] = $row + [
                'index' => $i,
                'net' => $net === null ? '' : $this->money($net, $currency),
                'errors' => [
                    'name' => $e('name'), 'description' => $e('description'), 'qty' => $e('qty_milli'), 'unit' => $e('unit'),
                    'price' => $e('unit_minor'), 'discount' => $e('discount_bp'), 'vat' => $e('vat_bp'), 'item' => $e('item_id'),
                    'row' => $messages["lines.$i"] ?? null,
                ],
            ];
        }

        // Totals panel: the posted/typed state when it computes, else the stored draft.
        $sign = $creditNote ? -1 : 1;
        $totals = null;
        $parsed = $form->parse($lang, $creditNote);
        if ($parsed['errors'] === [] && $vat['error'] === null) {
            try {
                $t = InvoiceForm::totals($parsed['data']['lines'], $rc, $currency,
                    $creditNote ? ($doc['eur_rate'] === null ? null : (string) $doc['eur_rate']) : $form->rate());
                $totals = $this->totalsView($t, $sign, $currency, $rc, $lang);
            } catch (ValidationException) {
                $totals = null;
            }
        }
        if ($totals === null && $doc !== null && $doc['lines'] !== []) {
            $totals = $this->totalsView($this->service()->totalsFor($doc), $sign, (string) $doc['currency'], (int) $doc['reverse_charge'] === 1, $lang);
        }

        $now = $this->now();
        $type = $doc === null ? DocType::INVOICE : (string) $doc['type'];
        $related = null;
        if ($creditNote && $doc['related_document_id'] !== null) {
            $inv = $this->docs()->row((int) $doc['related_document_id']);
            $related = $inv === null ? null : ['id' => (int) $inv['id'], 'number' => (string) $inv['number'], 'date' => $this->date($inv['issue_date'])];
        }
        $catalogue = [];
        foreach ($this->items()->list('', true) as $item) {
            $catalogue[] = ['id' => (int) $item['id'], 'name' => (string) $item['display_name'],
                'price' => $this->money((int) $item['price_minor'], (string) $item['currency'])];
        }
        $vatChoices = [];
        foreach ([1900, 700, 0] as $bp) {
            $vatChoices[] = ['value' => (string) $bp, 'label' => Percent::format($bp, $lang) . ' %'];
        }
        return $this->app->render('invoices/edit.twig', [
            'doc' => $doc === null ? null : ['id' => (int) $doc['id'], 'type' => $type],
            'credit_note' => $creditNote,
            'form' => $fields,
            'lines' => $lines,
            'errors' => $messages,
            'notice' => $notice,
            'flash' => $this->takeFlash(),
            'customers' => $this->customerChoices(),
            'customer' => $customer === null ? null : ['id' => (int) $customer['id'], 'name' => (string) $customer['name']],
            'banner' => $this->banner($doc, $vat, $customer),
            'reverse_charge' => $rc,
            'totals' => $totals,
            'paper' => $this->paperForEditor($doc, $form, $customer, $parsed, $rc, $currency),
            'next_number' => $this->nextNumber($type, $now),
            'can_issue' => $this->ctx()->canIssue($now),
            'confirm_vat' => array_key_exists('confirm_vat', $errors) ? false : $this->app->request()->input('confirm_vat') === '1',
            'related' => $related,
            'catalogue' => $catalogue,
            'units' => Units::KEYS,
            'vat_choices' => $vatChoices,
            'langs' => DocumentService::LANGS,
            'currencies' => DocumentService::CURRENCIES,
        ], $status);
    }

    /**
     * VAT treatment shown and used for live totals. Credit notes follow their invoice (stored);
     * a draft whose customer is unchanged uses its stored decision; otherwise (create mode or a
     * customer changed in the form) the decision for the selected customer as it is now.
     *
     * @param array<string, mixed>|null $doc
     * @return array{reverse_charge: bool, reason: ?string, live_reason: ?string, error: ?string}
     */
    private function vatContext(?array $doc, InvoiceForm $form): array
    {
        if ($doc !== null && $doc['type'] === DocType::CREDIT_NOTE) {
            return ['reverse_charge' => (int) $doc['reverse_charge'] === 1, 'reason' => $doc['vat_reason'], 'live_reason' => $doc['vat_reason'], 'error' => null];
        }
        $raw = $form->fields['customer_id'];
        $customer = ctype_digit($raw) ? $this->customers()->find((int) $raw) : null;
        $live = null;
        if ($customer !== null) {
            try {
                $live = VatRule::decide(VatRule::buyerFromCustomerRow($customer));
            } catch (\InvalidArgumentException) {
                $live = null;
            }
        }
        if ($doc !== null && $customer !== null && (int) $doc['customer_id'] === (int) $customer['id']) {
            return ['reverse_charge' => (int) $doc['reverse_charge'] === 1, 'reason' => $doc['vat_reason'],
                'live_reason' => $live?->reason, 'error' => null];
        }
        return ['reverse_charge' => $live !== null && $live->reverseCharge, 'reason' => $live?->reason, 'live_reason' => $live?->reason,
            'error' => $live === null ? 'document.err_customer' : null];
    }

    /**
     * @param array<string, mixed>|null $doc
     * @param array{reverse_charge: bool, reason: ?string, live_reason: ?string, error: ?string} $vat
     * @param array<string, mixed>|null $customer
     * @return array{kind: string, title: ?string, text: string, link: ?string, recheck: bool}|null
     */
    private function banner(?array $doc, array $vat, ?array $customer): ?array
    {
        $reason = $vat['reason'];
        $creditNote = $doc !== null && $doc['type'] === DocType::CREDIT_NOTE;
        $recheck = !$creditNote && $doc !== null && $vat['live_reason'] !== null && $vat['live_reason'] !== $reason;
        if ($vat['reverse_charge']) {
            return ['kind' => 'ok', 'title' => $this->app->t('invoice.reverse_charge_detected'),
                'text' => $this->app->t('invoice.reverse_charge_note'), 'link' => null, 'recheck' => $recheck];
        }
        if ($reason === 'vies_not_confirmed' && !$creditNote) {
            return ['kind' => 'warn', 'title' => $this->app->t('invoice.vies_unconfirmed_title'),
                'text' => $this->app->t('invoice.vies_unconfirmed'),
                'link' => $customer === null ? null : '/customers/' . (int) $customer['id'], 'recheck' => $recheck];
        }
        $key = match ($reason) {
            'no_vat_id' => 'invoice.vat_hint_no_vat_id',
            'private_customer' => 'invoice.vat_hint_private',
            'domestic' => 'invoice.vat_hint_domestic',
            'non_eu' => 'invoice.vat_hint_non_eu',
            default => null,
        };
        return $key === null ? null : ['kind' => 'info', 'title' => null, 'text' => $this->app->t($key), 'link' => null, 'recheck' => $recheck];
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
        if (!$isInvoice && $doc['related_document_id'] !== null) {
            $inv = $this->docs()->row((int) $doc['related_document_id']);
            $related = $inv === null ? null : ['id' => (int) $inv['id'], 'number' => (string) $inv['number'], 'date' => $this->date($inv['issue_date'])];
        }
        $totals = $this->service()->totalsFor($doc);
        return $this->app->render('invoices/show.twig', [
            'doc' => [
                'id' => $id, 'type' => (string) $doc['type'], 'number' => (string) $doc['number'],
                'issue_date' => $this->date($doc['issue_date']), 'service_date' => $this->date($doc['service_date']),
                'due_date' => $isInvoice ? $this->date($doc['due_date']) : null, 'currency' => $currency,
                'notes' => (string) ($doc['notes'] ?? ''), 'buyer' => (string) ($doc['buyer_name'] ?? ''),
            ],
            'status' => $summary['status'],
            'chip' => self::CHIPS[$summary['status']] ?? 'chip-draft',
            'summary' => $isInvoice ? [
                'gross' => $this->money($summary['gross_minor'], $currency), 'paid' => $this->money($summary['paid_minor'], $currency),
                'credited' => $this->money($summary['credited_minor'], $currency), 'balance' => $this->money($summary['balance_minor'], $currency),
            ] : null,
            'payments' => $payments,
            'credit_notes' => $creditNotes,
            'related' => $related,
            'paper' => $this->paper($doc, $doc['seller'] ?? [], $doc['buyer'] ?? [], $totals),
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

    // ---- paper preview (server-rendered HTML partial invoices/_paper.twig) ----

    /**
     * Paper of the editor: the SAVED draft; in create mode the typed state when it computes.
     *
     * @param array<string, mixed>|null $doc
     * @param array<string, mixed>|null $customer
     * @param array{data: array<string, mixed>, errors: array<string, string>, rows: list<int>} $parsed
     * @return array<string, mixed>|null
     */
    private function paperForEditor(?array $doc, InvoiceForm $form, ?array $customer, array $parsed, bool $rc, string $currency): ?array
    {
        $company = $this->ctx()->company();
        if ($doc !== null) {
            if ($doc['lines'] === []) {
                return null;
            }
            $buyer = $customer ?? [];
            if ($doc['type'] === DocType::CREDIT_NOTE && $doc['related_document_id'] !== null) {
                $buyer = $this->docs()->row((int) $doc['related_document_id'])['buyer'] ?? [];
            } elseif ($doc['customer_id'] !== null) {
                $buyer = $this->customers()->find((int) $doc['customer_id']) ?? [];
            }
            return $this->paper($doc, $company, $buyer, $this->service()->totalsFor($doc));
        }
        if ($parsed['errors'] !== [] || $customer === null) {
            return null;
        }
        try {
            $totals = InvoiceForm::totals($parsed['data']['lines'], $rc, $currency, $form->rate());
        } catch (ValidationException) {
            return null;
        }
        $lines = [];
        foreach ($parsed['data']['lines'] as $l) {
            $lines[] = ['name' => (string) $l['name'], 'description' => (string) $l['description'], 'unit' => (string) $l['unit'],
                'qty_milli' => (int) $l['qty_milli'], 'unit_minor' => (int) $l['unit_minor'], 'discount_bp' => (int) $l['discount_bp'],
                'vat_bp' => (int) $l['vat_bp'], 'net_minor' => null];
        }
        $pseudo = ['type' => DocType::INVOICE, 'number' => null, 'lang' => in_array($form->fields['lang'], DocumentService::LANGS, true) ? $form->fields['lang'] : 'de',
            'currency' => $currency, 'eur_rate' => $form->rate(), 'reverse_charge' => $rc ? 1 : 0,
            'vat_note_key' => $rc ? 'invoice.reverse_charge_note' : null, 'issue_date' => self::realDate($form->fields['issue_date']),
            'service_date' => self::realDate($form->fields['service_date']), 'due_date' => self::realDate($form->fields['due_date']),
            'intro_text' => $form->fields['intro_text'], 'outro_text' => $form->fields['outro_text'], 'lines' => $lines,
            'related_document_id' => null];
        return $this->paper($pseudo, $company, $customer, $totals);
    }

    /**
     * View model of the paper partial, in the DOCUMENT language. All values are plain strings;
     * the template escapes them (addresses are split into lines, never raw HTML).
     *
     * @param array<string, mixed> $doc document row with lines (or the create-mode pseudo row)
     * @param array<string, mixed> $seller companies row or seller snapshot
     * @param array<string, mixed> $buyer customers row or buyer snapshot
     * @return array<string, mixed>
     */
    private function paper(array $doc, array $seller, array $buyer, Totals $totals): array
    {
        $lang = in_array($doc['lang'], DocumentService::LANGS, true) ? (string) $doc['lang'] : 'de';
        $tr = fn (string $key, array $vars = []): string => $this->app->translator()->t($key, $lang, $vars);
        $fmt = new Formatter();
        $currency = (string) $doc['currency'];
        $rc = (int) $doc['reverse_charge'] === 1;
        $creditNote = $doc['type'] === DocType::CREDIT_NOTE;
        $sign = $creditNote ? -1 : 1;
        $money = fn (int $minor): string => $fmt->money(Money::of($minor, $currency), $lang);
        $date = fn (?string $d): ?string => $d === null || $d === '' ? null : $fmt->date(new \DateTimeImmutable($d), $lang);
        $rows = [];
        foreach ($doc['lines'] as $i => $l) {
            $net = $l['net_minor'] ?? Totals::lineNet(new Line((int) $l['qty_milli'], (int) $l['unit_minor'], (int) $l['discount_bp'], (int) $l['vat_bp']));
            $rows[] = [
                'pos' => $i + 1, 'name' => (string) $l['name'], 'description' => (string) ($l['description'] ?? ''),
                'qty' => $fmt->quantity((int) $l['qty_milli'], $lang), 'unit' => $tr('unit.' . $l['unit']),
                'price' => $money((int) $l['unit_minor']),
                'discount' => (int) $l['discount_bp'] === 0 ? '' : Percent::format((int) $l['discount_bp'], $lang) . ' %',
                'vat' => ($rc ? '0' : Percent::format((int) $l['vat_bp'], $lang)) . ' %',
                'net' => $money((int) $net),
            ];
        }
        $vat = [];
        foreach ($totals->vat as $bp => $amount) {
            $vat[] = ['label' => $tr('invoice.vat') . ' ' . Percent::format($bp, $lang) . ' %', 'amount' => $money($sign * $amount)];
        }
        if ($rc) {
            $vat[] = ['label' => $tr('invoice.vat') . ' 0 %', 'amount' => $money(0)];
        }
        $related = null;
        if ($creditNote && $doc['related_document_id'] !== null) {
            $inv = $this->docs()->row((int) $doc['related_document_id']);
            if ($inv !== null) {
                $related = $tr('invoice.corrects', ['number' => (string) $inv['number'], 'date' => (string) $date($inv['issue_date'])]);
            }
        }
        $lines = fn (mixed $text): array => array_values(array_filter(
            array_map('trim', explode("\n", str_replace(["\r\n", "\r"], "\n", (string) $text))),
            fn (string $l): bool => $l !== ''
        ));
        return [
            'lang' => $lang,
            'title' => $tr($creditNote ? 'invoice.type_credit_note' : 'invoice.title'),
            'number' => $doc['number'] ?? $tr('invoice.draft_number'),
            'labels' => [
                'bill_to' => $tr('invoice.bill_to'), 'position' => $tr('invoice.position'), 'description' => $tr('invoice.description'),
                'quantity' => $tr('invoice.quantity'), 'unit_price' => $tr('invoice.unit_price'), 'discount' => $tr('invoice.discount'),
                'vat' => $tr('invoice.vat'), 'amount' => $tr('invoice.amount'), 'net' => $tr('invoice.net'), 'total' => $tr('invoice.total'),
                'issue_date' => $tr('invoice.date'), 'service_date' => $tr('invoice.service_date'), 'due_date' => $tr('invoice.due_date'),
                'vat_id' => $tr('invoice.vat_id'), 'tax_number' => $tr('invoice.tax_number'), 'vat_in_eur' => $tr('invoice.vat_in_eur'),
                'exchange_rate' => $tr('invoice.exchange_rate'),
            ],
            'issue_date' => $date($doc['issue_date']),
            'service_date' => $date($doc['service_date']),
            'due_date' => $creditNote ? null : $date($doc['due_date']),
            'seller' => ['name' => (string) ($seller['name'] ?? ''), 'address' => $lines($seller['address'] ?? ''),
                'vat_id' => (string) ($seller['vat_id'] ?? ''), 'tax_number' => (string) ($seller['tax_number'] ?? ''),
                'iban' => (string) ($seller['iban'] ?? ''), 'bic' => (string) ($seller['bic'] ?? '')],
            'buyer' => ['name' => (string) ($buyer['name'] ?? ''), 'address' => $lines($buyer['address'] ?? ''),
                'vat_id' => (string) ($buyer['vat_id'] ?? '')],
            'intro' => $lines($doc['intro_text'] ?? ''),
            'outro' => $lines($doc['outro_text'] ?? ''),
            'rows' => $rows,
            'net' => $money($sign * $totals->netMinor),
            'vat' => $vat,
            'gross' => $money($sign * $totals->grossMinor),
            'vat_eur' => $currency !== 'EUR' && !$rc && $totals->vatEurMinor !== null
                ? $fmt->money(Money::of($sign * $totals->vatEurMinor, 'EUR'), $lang) : null,
            'rate' => $currency !== 'EUR' && $doc['eur_rate'] !== null && $doc['eur_rate'] !== '' ? '1 EUR = ' . $doc['eur_rate'] . ' ' . $currency : null,
            'note' => $doc['vat_note_key'] !== null && $doc['vat_note_key'] !== '' ? $tr((string) $doc['vat_note_key']) : null,
            'related' => $related,
        ];
    }

    /**
     * Totals block view model (UI language), signed for credit notes.
     *
     * @return array{net: string, vat: list<array{label: string, amount: string}>, gross: string, vat_eur: ?string}
     */
    private function totalsView(Totals $t, int $sign, string $currency, bool $rc, string $lang): array
    {
        $vat = [];
        foreach ($t->vat as $bp => $amount) {
            $vat[] = ['label' => $this->app->t('invoice.vat') . ' ' . Percent::format($bp, $lang) . ' %', 'amount' => $this->money($sign * $amount, $currency)];
        }
        if ($rc) {
            $vat[] = ['label' => $this->app->t('invoice.vat') . ' 0 % · ' . $this->app->t('invoice.reverse_charge'), 'amount' => $this->money(0, $currency)];
        }
        return [
            'net' => $this->money($sign * $t->netMinor, $currency),
            'vat' => $vat,
            'gross' => $this->money($sign * $t->grossMinor, $currency),
            'vat_eur' => $currency !== 'EUR' && !$rc && $t->vatEurMinor !== null ? $this->money($sign * $t->vatEurMinor, 'EUR') : null,
        ];
    }

    // ---- helpers ----

    /** @param array<string, mixed> $customer */
    private function defaultsFor(array $customer): InvoiceForm
    {
        $company = $this->ctx()->company();
        $today = $this->now();
        $lang = in_array($customer['default_lang'], DocumentService::LANGS, true) ? (string) $customer['default_lang'] : 'de';
        $currency = in_array($customer['default_currency'], DocumentService::CURRENCIES, true) ? (string) $customer['default_currency'] : 'EUR';
        $days = max(0, (int) $customer['payment_days']);
        return new InvoiceForm([
            'customer_id' => (string) (int) $customer['id'], 'lang' => $lang, 'currency' => $currency, 'eur_rate' => '',
            'issue_date' => $today->format('Y-m-d'), 'service_date' => $today->format('Y-m-d'),
            'due_date' => $today->modify("+{$days} days")->format('Y-m-d'),
            'intro_text' => (string) ($company['intro_text'] ?? ''), 'outro_text' => (string) ($company['outro_text'] ?? ''), 'notes' => '',
        ], [InvoiceForm::blankLine()]);
    }

    /** @return list<array{id: int, name: string, country: string}> */
    private function customerChoices(): array
    {
        return array_map(
            fn (array $c): array => ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'country' => (string) $c['country']],
            $this->customers()->list()
        );
    }

    private function nextNumber(string $type, \DateTimeImmutable $now): ?string
    {
        try {
            return (new NumberSequences($this->app->db(), $this->ctx(), fn (): int => $this->app->now()))->preview($type, $now);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The document if it is an invoice or credit note of the context company. @return array<string, mixed>|null */
    private function load(int $id): ?array
    {
        $doc = $this->docs()->find($id);
        return $doc !== null && in_array($doc['type'], self::LIST_TYPES, true) ? $doc : null;
    }

    private function locked(int $id): Response
    {
        $this->flash('invoice.locked', [], 'warn');
        return Response::redirect('/invoices/' . $id, 303);
    }

    /** @param array<string, string> $vars */
    private function flash(string $key, array $vars = [], string $kind = 'notice'): void
    {
        $this->app->session()->set(self::FLASH, ['text' => $this->app->t($key, $vars), 'kind' => $kind]);
    }

    /** @return array{text: string, kind: string}|null shown once */
    private function takeFlash(): ?array
    {
        $f = $this->app->session()->get(self::FLASH);
        $this->app->session()->remove(self::FLASH);
        return is_array($f) && is_string($f['text'] ?? null) && in_array($f['kind'] ?? null, ['notice', 'warn'], true) ? $f : null;
    }

    private function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->app->now()))->setTimezone(new \DateTimeZone('Europe/Berlin'));
    }

    private function date(?string $ymd): ?string
    {
        return $ymd === null || $ymd === '' ? null : (new Formatter())->date(new \DateTimeImmutable($ymd), $this->app->lang());
    }

    private function money(int $minor, string $currency): string
    {
        return (new Formatter())->money(Money::of($minor, $currency), $this->app->lang());
    }

    private static function realDate(string $v): ?string
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $v, $m) !== 1 || (int) $m[1] < 1) {
            return null;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
    }

    private function ctx(): CompanyContext
    {
        return $this->app->context() ?? throw new \LogicException('RequireCompany must run first.');
    }

    private function service(): DocumentService
    {
        return $this->app->documents();
    }

    private function docs(): DocumentRepo
    {
        return new DocumentRepo($this->app->db(), $this->ctx());
    }

    private function customers(): CustomerRepo
    {
        return new CustomerRepo($this->app->db(), $this->ctx());
    }

    private function items(): ItemRepo
    {
        return new ItemRepo($this->app->db(), $this->ctx(), $this->app->lang());
    }

    private function notFound(): Response
    {
        return $this->app->render('error.twig', [
            'status' => 404,
            'message' => $this->app->t('error.not_found'),
            'detail' => null,
        ], 404);
    }
}
