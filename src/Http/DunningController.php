<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Request;
use App\Core\Response;
use App\Domain\AccessSuspended;
use App\Domain\DocType;
use App\Domain\DocumentNotFound;
use App\Domain\Dunning;
use App\Domain\DunningLevel;
use App\Domain\DunningSettings;
use App\Domain\Percent;
use App\Domain\ValidationException;
use App\Repo\EmailLogRepo;

/**
 * Dunning letters (Task 21, R33). Nothing here sends anything: an issued letter is mailed only
 * through the existing send page (/documents/{id}/send, explicit POST).
 *
 * - GET /dunning: overdue invoices from the ONE aggregate query (DocumentRepo::paymentRows,
 *   status overdue, due date ascending) with days overdue, last issued letter and the suggested
 *   next level (Dunning::suggest), plus the list of issued letters.
 * - GET /dunning/new?invoice=ID[&level=N][&interest=1][&flat_fee=1][&fee=1]: the preview, computed
 *   on the fly by DocumentService::dunningQuote (not stored); a plain GET form recalculates it, so
 *   it works without JavaScript. Toggles are off unless explicitly asked for. Ineligible invoices
 *   (not overdue, paid, credited, final level reached, draft) redirect to the list with a flash.
 * - POST /dunning: createDunning() re-checks and recomputes everything on the server; only the
 *   invoice id, the level and the three toggles are read from the form (amounts never).
 * - GET /dunning/{id}: the issued letter from its stored columns and snapshots.
 *
 * Unknown ids, other companies' documents and other types give the same 404 as a missing id
 * (load() serves only dunning letters; the invoice parameter must be an invoice of the company).
 * Every member may create and send letters; suspended companies may look but not create.
 */
final class DunningController extends AbstractDocumentController
{
    /** Dot colour per level (reminder green, 1st orange, 2nd and final red). */
    private const DOTS = [0 => 'dot-ok', 1 => 'dot-soon', 2 => 'dot-late', 3 => 'dot-late'];
    private const TOGGLES = ['interest', 'flat_fee', 'fee'];

    protected function docType(): string
    {
        return DocType::DUNNING;
    }

    protected function loadTypes(): array
    {
        return [DocType::DUNNING];
    }

    protected function prefix(): string
    {
        return '/dunning';
    }

    protected function defaultDates(array $customer, \DateTimeImmutable $today): array
    {
        return [];
    }

    protected function viewIssued(array $doc): Response
    {
        return $this->view($doc);
    }

    // ---- list ----

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $query = ListQuery::fromRequest($request, []);
        $q = $query->q;
        $now = $this->now();
        $settings = DunningSettings::fromCompanyRow($this->ctx()->company());
        $filter = ['status' => 'overdue', 'q' => $q];
        $total = $this->docs()->paymentCount($filter, $now);
        $page = $query->clampedPage($total);
        $overdue = [];
        foreach ($this->docs()->paymentRows($filter, $now, ListQuery::PER_PAGE, ($page - 1) * ListQuery::PER_PAGE, 'due') as $r) {
            $days = Dunning::daysOverdue((string) $r['due_date'], $now);
            $last = $r['last_dunning_level'] === null ? null : DunningLevel::tryFrom((int) $r['last_dunning_level']);
            $suggested = Dunning::suggest($days, $last, $settings);
            $overdue[] = [
                'id' => (int) $r['id'], 'number' => (string) $r['number'], 'buyer' => (string) ($r['buyer_name'] ?? ''),
                'balance' => $this->money((int) $r['balance_minor'], (string) $r['currency']), 'due_date' => $this->date($r['due_date']),
                'days' => $days,
                'last' => $last === null ? null : ['label' => $this->app->t($last->translationKey()), 'date' => $this->date($r['last_dunning_date'])],
                'suggestion' => $suggested === null ? null : ['level' => $suggested->value, 'label' => $this->app->t($suggested->translationKey()), 'dot' => self::DOTS[$suggested->value]],
                'can_create' => $last !== DunningLevel::Final,
            ];
        }
        $pager = $query->pager('/dunning', ['q' => $q], $total);

        $issuedFilter = ['type' => DocType::DUNNING, 'status' => 'issued', 'q' => $q];
        $issuedTotal = $this->docs()->count($issuedFilter);
        $issuedQuery = ListQuery::fromRequest($request, [], 'dpage');
        $issuedPage = $issuedQuery->clampedPage($issuedTotal);
        $issued = [];
        foreach ($this->docs()->list($issuedFilter, ListQuery::PER_PAGE, ($issuedPage - 1) * ListQuery::PER_PAGE) as $d) {
            $inv = $d['related_document_id'] === null ? null : $this->docs()->row((int) $d['related_document_id']);
            $level = DunningLevel::tryFrom((int) $d['dunning_level']) ?? DunningLevel::Reminder;
            $issued[] = [
                'id' => (int) $d['id'], 'number' => (string) $d['number'], 'buyer' => (string) ($d['buyer_name'] ?? ''),
                'invoice' => $inv !== null && $inv['type'] === DocType::INVOICE ? ['id' => (int) $inv['id'], 'number' => (string) $inv['number']] : null,
                'level' => $this->app->t($level->translationKey()), 'dot' => self::DOTS[$level->value], 'date' => $this->date($d['issue_date']),
                'claim' => $this->money((int) $d['dunning_claim_minor'], (string) $d['currency']),
            ];
        }
        $issuedPager = $issuedQuery->pager('/dunning', ['q' => $q], $issuedTotal, 'dpage');
        return $this->app->render('dunning/list.twig', [
            'q' => $q,
            'overdue' => $overdue,
            'total' => $total,
            'issued' => $issued,
            'issued_total' => $issuedTotal,
            'issued_pager' => $issuedPager,
            'flash' => $this->takeFlash(),
        ] + $pager);
    }

    // ---- preview and creation ----

    /** @param array<string, string> $params */
    public function preview(Request $request, array $params): Response
    {
        $raw = $request->queryParam('invoice');
        if (!ctype_digit($raw)) {
            return $this->notFound();
        }
        $toggles = [];
        foreach (self::TOGGLES as $t) {
            $toggles[$t] = $request->queryParam($t) === '1';
        }
        return $this->previewPage((int) $raw, $request->queryParam('level'), $toggles, []);
    }

    /** @param array<string, string> $params */
    public function store(Request $request, array $params): Response
    {
        $raw = $request->input('invoice');
        if (!ctype_digit($raw)) {
            return $this->notFound();
        }
        $invoiceId = (int) $raw;
        $toggles = [];
        foreach (self::TOGGLES as $t) {
            $toggles[$t] = $request->input($t) === '1'; // off unless explicitly posted
        }
        $level = ctype_digit($request->input('level')) ? DunningLevel::tryFrom((int) $request->input('level')) : null;
        if ($level === null) {
            return $this->previewPage($invoiceId, '', $toggles, ['level' => 'dunning.err_level'], 422);
        }
        try {
            // One retry when MySQL aborts the creation with a deadlock/lock wait (nothing was written then).
            $id = $this->app->db()->retryOnLockError(fn (): int => $this->service()->createDunning($invoiceId, $level, $toggles['interest'], $toggles['flat_fee'], $toggles['fee'], $this->now()));
        } catch (DocumentNotFound) {
            return $this->notFound();
        } catch (AccessSuspended) {
            return $this->previewPage($invoiceId, (string) $level->value, $toggles, ['document' => 'invoice.suspended'], 403);
        } catch (ValidationException $e) {
            if (isset($e->errors['invoice'])) {
                $this->flash($e->errors['invoice'], [], 'warn');
                return Response::redirect('/dunning', 303);
            }
            return $this->previewPage($invoiceId, (string) $level->value, $toggles, $e->errors, 422);
        }
        $this->flash('dunning.created', ['number' => (string) ($this->docs()->row($id)['number'] ?? '')]);
        return Response::redirect('/dunning/' . $id, 303);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $doc = $this->load((int) $params['id']);
        return $doc === null || $doc['status'] !== 'issued' ? $this->notFound() : $this->view($doc);
    }

    /**
     * The preview page for an invoice. $levelRaw is the requested level ('' = suggestion, else the
     * lowest allowed one); the amounts are DocumentService::dunningQuote() for exactly these inputs.
     *
     * @param array{interest: bool, flat_fee: bool, fee: bool} $toggles
     * @param array<string, string> $errors field => translation key
     */
    private function previewPage(int $invoiceId, string $levelRaw, array $toggles, array $errors, int $status = 200): Response
    {
        $now = $this->now();
        try {
            $ctx = $this->service()->dunningContext($invoiceId, $now);
        } catch (DocumentNotFound) {
            return $this->notFound();
        } catch (ValidationException $e) {
            $this->flash((string) (array_values($e->errors)[0] ?? 'error.bad_request'), [], 'warn');
            return Response::redirect('/dunning', 303);
        }
        /** @var list<DunningLevel> $levels */
        $levels = $ctx['levels'];
        $level = in_array($ctx['suggested'], $levels, true) ? $ctx['suggested'] : $levels[0];
        if ($levelRaw !== '') {
            $asked = ctype_digit($levelRaw) ? DunningLevel::tryFrom((int) $levelRaw) : null;
            if ($asked !== null && in_array($asked, $levels, true)) {
                $level = $asked;
            } else {
                $errors += ['level' => $asked === null ? 'dunning.err_level' : 'dunning.err_level_not_higher'];
                $status = max($status, 422);
            }
        }
        $quote = null;
        try {
            $quote = $this->service()->dunningQuote($invoiceId, $level, $toggles['interest'], $toggles['flat_fee'], $toggles['fee'], $now);
        } catch (ValidationException $e) {
            $errors += $e->errors;
            $status = max($status, 422);
        }

        $lang = $this->app->lang();
        $currency = (string) $ctx['currency'];
        $eur = fn (int $minor): string => $this->money($minor, 'EUR');
        $breakdown = null;
        $letter = null;
        if ($quote !== null) {
            $pseudo = self::pseudoRow($quote);
            $breakdown = $this->presenter()->dunningRows($pseudo, $lang) + ['pay_by' => $this->date($quote['pay_by'])];
            $invoice = $this->docs()->row($invoiceId);
            $letter = $this->presenter()->dunning($pseudo, $this->ctx()->company(), (array) ($invoice['buyer'] ?? []), $invoice);
        }
        $rate = $ctx['interest_rate_bp'];
        $isOwner = $this->ctx()->role() === 'owner';
        return $this->app->render('dunning/preview.twig', [
            'invoice' => [
                'id' => $invoiceId, 'number' => (string) $ctx['number'], 'buyer' => (string) $ctx['buyer_name'], 'due_date' => $this->date($ctx['due_date']),
                'days' => (int) $ctx['days_overdue'], 'outstanding' => $this->money((int) $ctx['outstanding_minor'], $currency), 'currency' => $currency,
                'last' => $ctx['last_level'] === null ? null : ['label' => $this->app->t($ctx['last_level']->translationKey()), 'date' => $this->date($ctx['last_date'])],
                'suggested' => $ctx['suggested'] === null ? null : ['label' => $this->app->t($ctx['suggested']->translationKey()), 'dot' => self::DOTS[$ctx['suggested']->value]],
            ],
            'level' => $level->value,
            'level_label' => $this->app->t($level->translationKey()),
            'levels' => array_map(fn (DunningLevel $l): array => ['value' => $l->value, 'label' => $this->app->t($l->translationKey()),
                'suggested' => $l === $ctx['suggested']], $levels),
            'toggles' => $toggles,
            'interest' => [
                'available' => (bool) $ctx['base_rate_set'],
                // The company's configured B2B surcharge (percentage points over the base rate), not a literal in the text.
                'points' => Percent::format(DunningSettings::fromCompanyRow($this->ctx()->company())->b2bSurchargeBp, $lang),
                'hint' => $rate === null ? null : $this->app->t('dunning.interest_hint', ['rate' => Percent::format((int) $rate, $lang),
                    'kind' => $this->app->t($ctx['is_company'] ? 'dunning.kind_b2b' : 'dunning.kind_b2c'), 'days' => (string) $ctx['days_overdue']]),
            ],
            'flat_fee' => ['available' => (bool) $ctx['flat_fee_allowed'], 'amount' => $eur((int) $ctx['flat_fee_setting_minor'])],
            'fee' => ['shown' => $ctx['fee_setting_minor'] > 0 && $currency === 'EUR', 'available' => (bool) $ctx['fee_allowed'], 'amount' => $eur((int) $ctx['fee_setting_minor'])],
            'warnings' => [
                'no_base_rate' => !$ctx['base_rate_set'],
                'consumer' => !$ctx['is_company'],
                'non_eur' => $currency !== 'EUR' ? $currency : null,
            ],
            'is_owner' => $isOwner,
            'breakdown' => $breakdown,
            'letter' => $letter,
            'letter_lang' => $this->app->t('lang.' . $ctx['lang']),
            'can_issue' => (bool) $ctx['can_issue'],
            'errors' => array_map(fn (string $k): string => $this->app->t($k), $errors),
            'flash' => $this->takeFlash(),
        ], $status);
    }

    // ---- issued view ----

    /** @param array<string, mixed> $doc an issued dunning letter (DocumentRepo::find) */
    private function view(array $doc): Response
    {
        $id = (int) $doc['id'];
        $lang = $this->app->lang();
        $invoice = $doc['related_document_id'] === null ? null : $this->docs()->row((int) $doc['related_document_id']);
        $level = DunningLevel::tryFrom((int) $doc['dunning_level']) ?? DunningLevel::Reminder;
        $mailLog = new EmailLogRepo($this->app->db(), $this->ctx());
        return $this->app->render('dunning/show.twig', [
            'doc' => [
                'id' => $id, 'number' => (string) $doc['number'], 'buyer' => (string) ($doc['buyer_name'] ?? ''),
                'issue_date' => $this->date($doc['issue_date']), 'pay_by' => $this->date($doc['dunning_pay_by']), 'currency' => (string) $doc['currency'],
            ],
            'level_label' => $this->app->t($level->translationKey()),
            'dot' => self::DOTS[$level->value],
            'invoice' => $invoice !== null && $invoice['type'] === DocType::INVOICE
                ? ['id' => (int) $invoice['id'], 'number' => (string) $invoice['number'], 'due_date' => $this->date($invoice['due_date'])] : null,
            'breakdown' => $this->presenter()->dunningRows($doc, $lang),
            'letter' => $this->presenter()->issued($doc),
            'letter_lang' => $this->app->t('lang.' . (in_array($doc['lang'], ['de', 'pl', 'en'], true) ? $doc['lang'] : 'de')),
            'mail_log' => MailController::logRows($mailLog->forDocument($id), $lang),
            'mail_sent' => $mailLog->hasSent($id),
            'flash' => $this->takeFlash(),
        ]);
    }

    /**
     * The quote as a dunning row (the stored column names), so the preview and the issued letter
     * go through the same presenter code.
     *
     * @param array<string, mixed> $quote DocumentService::dunningQuote()
     * @return array<string, mixed>
     */
    private static function pseudoRow(array $quote): array
    {
        return [
            'type' => DocType::DUNNING, 'number' => null, 'lang' => $quote['lang'], 'currency' => $quote['currency'],
            'issue_date' => $quote['issue_date'], 'related_document_id' => $quote['invoice_id'],
            'dunning_level' => $quote['level']->value, 'dunning_outstanding_minor' => $quote['outstanding_minor'],
            'dunning_interest_minor' => $quote['interest_minor'], 'dunning_interest_days' => $quote['interest_days'],
            'dunning_interest_rate_bp' => $quote['interest_rate_bp'], 'dunning_flat_fee_minor' => $quote['flat_fee_minor'],
            'dunning_fee_minor' => $quote['fee_minor'], 'dunning_claim_minor' => $quote['claim_minor'], 'dunning_pay_by' => $quote['pay_by'],
        ];
    }
}
