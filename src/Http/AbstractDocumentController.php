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
use App\Domain\NumberSequences;
use App\Domain\Percent;
use App\Domain\Units;
use App\Domain\ValidationException;
use App\Domain\VatNeedsConfirmation;
use App\Domain\VatRule;
use App\Money\Formatter;
use App\Money\Money;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Repo\ItemRepo;
use App\Tenant\CompanyContext;

/**
 * Behaviour shared by the document pages (invoices and credit notes: InvoiceController, offers:
 * OfferController): the draft editor (create mode and saved drafts), save/issue/delete, the live
 * totals JSON, the VAT banners, the paper preview and the small helpers.
 *
 * A subclass declares what is specific to it:
 * - docType(): the type of NEW drafts (the type never comes from the form);
 * - loadTypes(): the types its routes serve. load() answers null for every other type, so an
 *   offer id on an invoice route (and the reverse) is the same 404 as an unknown or foreign id;
 * - prefix(): the route prefix ("/invoices", "/offers");
 * - defaultDates(): the dates a new draft starts with (due date for invoices, valid until for offers);
 * - viewIssued(): the read-only page of an issued document.
 * Anything else (payments, corrections, offer status, conversion, list) lives in the subclass.
 * The parser is always InvoiceForm; the type decides which head fields it hands to the service.
 *
 * Rules (unchanged from Task 16):
 * - Every route sits behind RequireCompany; ids come from route params only and every read goes
 *   through company-scoped repos.
 * - issue() of the service is called with NO transaction open and without reading the document
 *   in a surrounding transaction (DocumentService SNAPSHOTS); the draft is saved first (its own
 *   committed transaction), then issued at the app clock's Berlin "now".
 * - Issued documents are shown only from their stored snapshots; they have no edit/delete
 *   controls and write routes redirect back to the view.
 * - The editor works without JavaScript: add/remove line and "add from catalogue" are submit
 *   buttons that re-render the posted state without saving.
 */
abstract class AbstractDocumentController
{
    /** Session key of the one-shot flash message (also set by MailController). */
    public const FLASH = 'invoice_flash';
    private const ISSUE_ERRORS = 'invoice_issue_errors';

    public function __construct(protected App $app)
    {
    }

    /** The type of new drafts created by this controller. */
    abstract protected function docType(): string;

    /** @return list<string> the document types this controller's routes serve */
    abstract protected function loadTypes(): array;

    /** Route prefix without trailing slash, e.g. "/invoices". */
    abstract protected function prefix(): string;

    /**
     * Initial date fields of a new draft (keys among issue_date, service_date, due_date, valid_until).
     *
     * @param array<string, mixed> $customer
     * @return array<string, string>
     */
    abstract protected function defaultDates(array $customer, \DateTimeImmutable $today): array;

    /** @param array<string, mixed> $doc issued document (DocumentRepo::find) */
    abstract protected function viewIssued(array $doc): Response;

    protected function isOffer(): bool
    {
        return $this->docType() === DocType::OFFER;
    }

    /** Where a document's page lives (also used by the mail page). */
    public static function pathFor(string $type, int $id): string
    {
        return match ($type) {
            DocType::OFFER => '/offers/',
            DocType::DUNNING => '/dunning/',
            default => '/invoices/',
        } . $id;
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
                'prefix' => $this->prefix(),
                'kind' => $this->docType(),
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
        $parsed = $form->parse($this->app->lang(), false, $this->isOffer());
        if ($parsed['errors'] !== []) {
            return $this->editor(null, $form, $parsed['errors'], 422);
        }
        try {
            $id = $this->service()->createDraft($this->docType(), $parsed['data']);
        } catch (ValidationException $e) {
            return $this->editor(null, $form, InvoiceForm::remapErrors($e->errors, $parsed['rows']), 422);
        }
        if ($request->input('intent') === 'issue' || $request->input('then') === 'send') {
            return $this->issueSaved($id, $request, $form, true);
        }
        $this->flash('invoice.saved');
        return Response::redirect($this->prefix() . '/' . $id, 303);
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
            return $this->editor($doc, InvoiceForm::fromDocument($doc, $this->app->lang()), $this->takeIssueErrors((int) $doc['id']));
        }
        return $this->viewIssued($doc);
    }

    /** @param array<string, string> $params */
    public function save(Request $request, array $params): Response
    {
        $result = $this->saveDraft((int) $params['id'], $request);
        if ($result instanceof Response) {
            return $result;
        }
        $this->flash('invoice.saved');
        return Response::redirect($this->prefix() . '/' . $result, 303);
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
        return Response::redirect($this->prefix(), 303);
    }

    /**
     * Live totals for the editor: the same fields as save, parsed by InvoiceForm, totals by
     * Totals::compute. Nothing is persisted. Optional `document_id` (a document of this company
     * AND of this controller's types, else 404) supplies the credit-note context (fixed VAT
     * treatment, currency, rate, negative sign).
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
        $parsed = $form->parse($this->app->lang(), $creditNote, $this->isOffer());
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
        $view = $this->presenter()->totalsPanel($totals, $sign, $currency, $rc, $this->app->lang());
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
        $parsed = $form->parse($this->app->lang(), $doc['type'] === DocType::CREDIT_NOTE, $this->isOffer());
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

    /**
     * Issues a SAVED draft (no transaction open here). With $justCreated (create mode: the draft was inserted by
     * this very POST) a refused issue is answered with a redirect to the new draft (post/redirect/get): re-rendering
     * under POST /invoices would let a refresh or a re-submit insert a second draft.
     */
    private function issueSaved(int $id, Request $request, InvoiceForm $form, bool $justCreated = false): Response
    {
        $confirm = $request->input('confirm_vat') === '1';
        try {
            // One retry when MySQL aborts the issue with a deadlock/lock wait (nothing was written then).
            $number = $this->app->db()->retryOnLockError(fn (): string => $this->service()->issue($id, $this->now(), $confirm));
        } catch (VatNeedsConfirmation) {
            return $justCreated ? $this->savedNotIssued($id, ['confirm_vat' => 'invoice.err_confirm_vat']) : $this->editorFor($id, $form, ['confirm_vat' => 'invoice.err_confirm_vat'], 422);
        } catch (AccessSuspended) {
            return $justCreated ? $this->savedNotIssued($id, ['document' => 'invoice.suspended']) : $this->editorFor($id, $form, ['document' => 'invoice.suspended'], 403);
        } catch (ValidationException $e) {
            return $justCreated ? $this->savedNotIssued($id, $e->errors) : $this->editorFor($id, $form, $e->errors, 422);
        } catch (DocumentLocked) {
            return $this->locked($id);
        } catch (DocumentNotFound) {
            return $this->notFound();
        }
        $this->app->archiveIssued($id);
        $this->flash('invoice.issued_as', ['number' => $number]);
        // "Issue and prepare email": the same issue as above, then straight to the send page (nothing is sent there yet).
        return Response::redirect($request->input('then') === 'send' ? '/documents/' . $id . '/send' : $this->prefix() . '/' . $id, 303);
    }

    /**
     * PRG after a refused issue in create mode: the draft is kept; the field errors travel in the session and are
     * shown ONCE by the draft's editor (same banners and fix links as a 422 render), plus a flash line.
     *
     * @param array<string, string> $errors field => translation key
     */
    private function savedNotIssued(int $id, array $errors): Response
    {
        $this->flash('invoice.saved_not_issued', ['reason' => $this->app->t((string) (array_values($errors)[0] ?? 'error.bad_request'))], 'warn');
        $this->app->session()->set(self::ISSUE_ERRORS, ['id' => $id, 'errors' => $errors]);
        return Response::redirect($this->prefix() . '/' . $id, 303);
    }

    /** @return array<string, string> the refusal kept by savedNotIssued() for this draft (once), else [] */
    private function takeIssueErrors(int $id): array
    {
        $kept = $this->app->session()->get(self::ISSUE_ERRORS);
        $this->app->session()->remove(self::ISSUE_ERRORS);
        if (!is_array($kept) || ($kept['id'] ?? null) !== $id || !is_array($kept['errors'] ?? null)) {
            return [];
        }
        return array_filter($kept['errors'], fn ($v, $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
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
                $totals = $this->presenter()->totalsPanel($t, $sign, $currency, $rc, $lang);
            } catch (ValidationException) {
                $totals = null;
            }
        }
        if ($totals === null && $doc !== null && $doc['lines'] !== []) {
            $totals = $this->presenter()->totalsPanel($this->service()->totalsFor($doc), $sign, (string) $doc['currency'], (int) $doc['reverse_charge'] === 1, $lang);
        }

        $now = $this->now();
        $type = $doc === null ? $this->docType() : (string) $doc['type'];
        $related = null;
        if ($creditNote && $doc['related_document_id'] !== null) {
            $inv = $this->docs()->row((int) $doc['related_document_id']);
            $related = $inv === null ? null : ['id' => (int) $inv['id'], 'number' => (string) $inv['number'], 'date' => $this->date($inv['issue_date'])];
        }
        $sourceOffer = null;
        if ($doc !== null && $type === DocType::INVOICE && $doc['related_document_id'] !== null) {
            $src = $this->docs()->row((int) $doc['related_document_id']);
            $sourceOffer = $src !== null && $src['type'] === DocType::OFFER && $src['number'] !== null
                ? ['id' => (int) $src['id'], 'number' => (string) $src['number']] : null;
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
            'prefix' => $this->prefix(),
            'kind' => $this->docType(),
            'source_offer' => $sourceOffer,
            'credit_note' => $creditNote,
            'form' => $fields,
            'lines' => $lines,
            'errors' => $messages,
            'notice' => $notice,
            'flash' => $this->takeFlash(),
            'customers' => $this->customerChoices(),
            'customer' => $customer === null ? null : ['id' => (int) $customer['id'], 'name' => (string) $customer['name']],
            'banner' => $this->banner($doc, $vat, $customer),
            'backdate' => $this->backdateWarning($type, $fields['issue_date']),
            'missing_data' => $this->missingData($errors, $customer),
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
     * M5: warning (never a block) when the chosen issue date lies before the latest issued document of the
     * same type: numbers then no longer rise with the dates, which an auditor will ask about.
     */
    private function backdateWarning(string $type, string $issueDate): ?string
    {
        $date = self::realDate($issueDate);
        $latest = $date === null ? null : $this->docs()->latestIssueDate($type);
        if ($latest === null || $date >= $latest) {
            return null;
        }
        return $this->app->t('invoice.backdate_warning', ['date' => $this->date($latest), 'type' => $this->app->t('invoice.type_' . $type)]);
    }

    /**
     * Banners for issue refusals because of missing mandatory data (R29), with a link to the
     * place where the user fixes it: company settings for the seller, the customer page for the buyer.
     *
     * @param array<string, string> $errors field => translation key
     * @param array<string, mixed>|null $customer
     * @return list<array{key: string, text: string, link: ?string, label: string}>
     */
    private function missingData(array $errors, ?array $customer): array
    {
        $out = [];
        foreach (['seller', 'seller_vat'] as $key) {
            if (isset($errors[$key])) {
                $out[] = ['key' => $key, 'text' => $this->app->t($errors[$key]), 'link' => '/settings/company', 'label' => $this->app->t('invoice.fix_company')];
            }
        }
        if (isset($errors['buyer'])) {
            $out[] = ['key' => 'buyer', 'text' => $this->app->t($errors['buyer']),
                'link' => $customer === null ? null : '/customers/' . (int) $customer['id'], 'label' => $this->app->t('invoice.fix_customer')];
        }
        return $out;
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
     * @return array{kind: string, title: ?string, text: string, link: ?string, recheck: bool, confirm: bool}|null
     */
    private function banner(?array $doc, array $vat, ?array $customer): ?array
    {
        $reason = $vat['reason'];
        $creditNote = $doc !== null && $doc['type'] === DocType::CREDIT_NOTE;
        $recheck = !$creditNote && $doc !== null && $vat['live_reason'] !== null && $vat['live_reason'] !== $reason;
        if ($vat['reverse_charge']) {
            return ['kind' => 'ok', 'title' => $this->app->t('invoice.reverse_charge_detected'),
                'text' => $this->app->t('invoice.reverse_charge_note'), 'link' => null, 'recheck' => $recheck, 'confirm' => false];
        }
        if ($reason === 'vies_not_confirmed' && !$creditNote) {
            // Invoices need the explicit confirmation to issue with German VAT; an offer does not (R30).
            return ['kind' => 'warn', 'title' => $this->app->t('invoice.vies_unconfirmed_title'),
                'text' => $this->app->t($this->isOffer() ? 'offer.vies_unconfirmed' : 'invoice.vies_unconfirmed'),
                'link' => $customer === null ? null : '/customers/' . (int) $customer['id'], 'recheck' => $recheck,
                'confirm' => !$this->isOffer()];
        }
        $key = match ($reason) {
            'no_vat_id' => 'invoice.vat_hint_no_vat_id',
            'private_customer' => 'invoice.vat_hint_private',
            'domestic' => 'invoice.vat_hint_domestic',
            'non_eu' => 'invoice.vat_hint_non_eu',
            default => null,
        };
        return $key === null ? null : ['kind' => 'info', 'title' => null, 'text' => $this->app->t($key), 'link' => null, 'recheck' => $recheck, 'confirm' => false];
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
            return $this->presenter()->present($doc, $company, $buyer, $this->service()->totalsFor($doc));
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
        $pseudo = ['type' => $this->docType(), 'number' => null, 'lang' => in_array($form->fields['lang'], DocumentService::LANGS, true) ? $form->fields['lang'] : 'de',
            'currency' => $currency, 'eur_rate' => $form->rate(), 'reverse_charge' => $rc ? 1 : 0,
            'vat_note_key' => $rc ? 'invoice.reverse_charge_note' : null, 'issue_date' => self::realDate($form->fields['issue_date']),
            'service_date' => self::realDate($form->fields['service_date']), 'due_date' => self::realDate($form->fields['due_date']),
            'valid_until' => self::realDate($form->fields['valid_until']),
            'intro_text' => $form->fields['intro_text'], 'outro_text' => $form->fields['outro_text'], 'lines' => $lines,
            'related_document_id' => null];
        return $this->presenter()->present($pseudo, $company, $customer, $totals);
    }

    // ---- helpers ----

    /** @param array<string, mixed> $customer */
    private function defaultsFor(array $customer): InvoiceForm
    {
        $company = $this->ctx()->company();
        $lang = in_array($customer['default_lang'], DocumentService::LANGS, true) ? (string) $customer['default_lang'] : 'de';
        $currency = in_array($customer['default_currency'], DocumentService::CURRENCIES, true) ? (string) $customer['default_currency'] : 'EUR';
        return new InvoiceForm(array_merge([
            'customer_id' => (string) (int) $customer['id'], 'lang' => $lang, 'currency' => $currency, 'eur_rate' => '',
            'issue_date' => '', 'service_date' => '', 'due_date' => '', 'valid_until' => '',
            'intro_text' => (string) ($company['intro_text'] ?? ''), 'outro_text' => (string) ($company['outro_text'] ?? ''), 'notes' => '',
        ], $this->defaultDates($customer, $this->now()), $this->isOffer() ? [] : ['valid_until' => '']), [InvoiceForm::blankLine()]);
    }

    /** @return list<array{id: int, name: string, country: string}> */
    protected function customerChoices(): array
    {
        return array_map(
            fn (array $c): array => ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'country' => (string) $c['country']],
            $this->customers()->list()
        );
    }

    protected function nextNumber(string $type, \DateTimeImmutable $now): ?string
    {
        try {
            return (new NumberSequences($this->app->db(), $this->ctx(), fn (): int => $this->app->now()))->preview($type, $now);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The document if it is of this controller's types and of the context company (another
     * company's id, an id of another type and an unknown id all give null).
     *
     * @return array<string, mixed>|null
     */
    protected function load(int $id): ?array
    {
        $doc = $this->docs()->find($id);
        return $doc !== null && in_array($doc['type'], $this->loadTypes(), true) ? $doc : null;
    }

    private function locked(int $id): Response
    {
        $this->flash('invoice.locked', [], 'warn');
        return Response::redirect($this->prefix() . '/' . $id, 303);
    }

    /** @param array<string, string> $vars */
    protected function flash(string $key, array $vars = [], string $kind = 'notice'): void
    {
        $this->app->session()->set(self::FLASH, ['text' => $this->app->t($key, $vars), 'kind' => $kind]);
    }

    /** @return array{text: string, kind: string}|null shown once */
    protected function takeFlash(): ?array
    {
        $f = $this->app->session()->get(self::FLASH);
        $this->app->session()->remove(self::FLASH);
        return is_array($f) && is_string($f['text'] ?? null) && in_array($f['kind'] ?? null, ['notice', 'warn'], true) ? $f : null;
    }

    protected function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->app->now()))->setTimezone(new \DateTimeZone('Europe/Berlin'));
    }

    protected function date(?string $ymd): ?string
    {
        return $ymd === null || $ymd === '' ? null : (new Formatter())->date(new \DateTimeImmutable($ymd), $this->app->lang());
    }

    protected function money(int $minor, string $currency): string
    {
        return (new Formatter())->money(Money::of($minor, $currency), $this->app->lang());
    }

    protected static function realDate(string $v): ?string
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $v, $m) !== 1 || (int) $m[1] < 1) {
            return null;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
    }

    protected function ctx(): CompanyContext
    {
        return $this->app->context() ?? throw new \LogicException('RequireCompany must run first.');
    }

    protected function service(): DocumentService
    {
        return $this->app->documents();
    }

    protected function docs(): DocumentRepo
    {
        return new DocumentRepo($this->app->db(), $this->ctx());
    }

    protected function customers(): CustomerRepo
    {
        return new CustomerRepo($this->app->db(), $this->ctx());
    }

    protected function presenter(): DocumentPresenter
    {
        return new DocumentPresenter($this->app->translator(), $this->docs());
    }

    protected function items(): ItemRepo
    {
        return new ItemRepo($this->app->db(), $this->ctx(), $this->app->lang());
    }

    protected function notFound(): Response
    {
        return $this->app->render('error.twig', [
            'status' => 404,
            'message' => $this->app->t('error.not_found'),
            'detail' => null,
        ], 404);
    }
}
