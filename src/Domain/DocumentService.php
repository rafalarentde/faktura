<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Audit;
use App\Core\Db;
use App\Money\Money;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Tenant\CompanyContext;

/**
 * Life cycle of documents of the context company: drafts, issuing (number + immutable
 * snapshots), payments and corrections (credit notes).
 *
 * - Drafts have no number; deleting one never touches a counter.
 * - issue() runs in ONE transaction: locking read of the draft, access check, VAT decision from
 *   the buyer as it is NOW, totals via Totals, number via NumberSequences::next() inside the same
 *   transaction (a failure anywhere rolls the counter back: no gap), snapshots, audit row.
 * - Issued documents are immutable: there is no method that changes or deletes an issued
 *   document, its lines or its payments (DocumentRepo writers refuse non-drafts as well).
 * - Totals are stored with the document's sign (credit notes negative); lines are positive.
 *   totalsFor() recomputes the unsigned Totals from the lines; stored = sign x recomputed.
 * - Every id comes from the caller but every read/write is company-scoped; an id of another
 *   company behaves exactly like an unknown id (DocumentNotFound).
 *
 * LOCK ORDER (InnoDB locking reads; SQLite serializes writers anyway). Paths that lock several
 * rows take them in this order:
 *  1. the invoice row: for a credit note its RELATED invoice, taken FIRST; addPayment and
 *     createCorrection lock only the invoice;
 *  2. the document's own row (lockedDraft);
 *  3. scans under the invoice lock: issued credit notes / payments of that invoice (locking
 *     reads, so they see current data);
 *  4. the number_formats row, then the counter rows (NumberSequences::next(), see its docblock).
 *     The numberTaken() check of the drawn number is a PLAIN read: it takes no lock, a number
 *     committed after our snapshot is caught by the UNIQUE index (company_id, type, number) and
 *     the SQLSTATE-23 retry draws the next counter value.
 * Step 3 runs BEFORE step 4 so the per-type number_formats row (the most contended lock of the
 * company) is never held while waiting for per-invoice rows; while holding it a transaction only
 * locks counter rows and writes its own (already locked) document row.
 * updateDraft/deleteDraft lock only the draft's own row (also for a draft credit note: harmless,
 * because a transaction holding only that row never waits for anything else); createDraft locks
 * nothing existing.
 * Residual risk: the step-3 scans take next-key locks, which on InnoDB can reach the first index
 * record of a NEIGHBOURING invoice's range; two credit-note issues for two different invoices
 * whose ranges are adjacent could then block each other in a rare cycle. InnoDB rolls one back
 * (1213); nothing is written and no number is consumed.
 *
 * OFFERS (Task 19): an offer is issued like an invoice (own 'offer' number sequence) but is no tax
 * invoice (R30): the gate asks only for both names, an unconfirmed VIES result never blocks it, it
 * has no due date, and valid_until defaults to issue date + 30 days. Issued offers stay immutable;
 * only offer_status (open | sent | accepted | rejected, 'expired' is computed) changes, through
 * setOfferStatus()/markOfferSent() under the offer's row lock. convertOffer() locks the same row,
 * so a double click creates one draft. Neither takes another document's lock (no new lock order).
 *
 * SNAPSHOTS: under REPEATABLE READ the first plain SELECT of a transaction fixes its read view.
 * issue() therefore does its plain pre-read (type, related_document_id) BEFORE opening its
 * transaction, and every plain read inside the transaction (lines, customer, company) comes
 * after the row locks, so it sees every commit that preceded them. Callers must not read the
 * document in their own surrounding transaction before calling issue() (issue() joins an outer
 * transaction and cannot control what ran before).
 */
final class DocumentService
{
    /** Types createDraft() accepts. Credit notes come only from createCorrection(). */
    public const DRAFT_TYPES = [DocType::INVOICE, DocType::OFFER];
    public const LANGS = ['de', 'pl', 'en'];
    public const CURRENCIES = ['EUR', 'PLN', 'USD'];
    private const MAX_LINES = 200;
    private const TEXT_MAX = 2000;
    private const NAME_MAX = 190;
    private const NOTE_MAX = 190;
    private const QTY_MAX = 999_999_999;
    private const PRICE_MAX = 99_999_999_999;
    private const NUMBER_ATTEMPTS = 50;
    /** Stored offer statuses (issued offers); 'expired' is computed, never stored. */
    public const OFFER_STATUSES = ['open', 'sent', 'accepted', 'rejected'];
    private const OFFER_OPEN = 'open';
    private const OFFER_VALID_DAYS = 30;
    /** A dunning letter asks for payment within this many days of its issue date (R33: fixed for now). */
    public const DUNNING_PAY_DAYS = 7;
    private const TEXT_FIELDS = ['intro_text', 'outro_text', 'notes'];
    private const DATE_FIELDS = ['issue_date', 'service_date', 'due_date', 'valid_until'];
    private const INPUT_FIELDS = ['customer_id', 'lang', 'currency', 'eur_rate', 'issue_date', 'service_date', 'due_date',
        'valid_until', 'intro_text', 'outro_text', 'notes', 'lines'];
    /** Fields of a credit note fixed by its invoice. */
    private const CREDIT_NOTE_FIXED = ['customer_id', 'currency', 'lang', 'eur_rate', 'related_document_id'];
    private const BUYER_FIELDS = ['name', 'address', 'country', 'vat_id', 'is_company', 'email', 'vies_status', 'vies_checked_at'];
    private const SELLER_FIELDS = ['name', 'address', 'tax_number', 'vat_id', 'iban', 'bic', 'bank', 'logo_path',
        'intro_text', 'outro_text', 'footer_text'];

    private \Closure $clock;
    private ?Audit $audit = null;

    public function __construct(
        private Db $db,
        private CompanyContext $ctx,
        private NumberSequences $numbers,
        private DocumentRepo $docs,
        private CustomerRepo $customers,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    // ---- drafts ----

    /**
     * Creates a draft invoice or offer. $data: customer_id (required), lang/currency (default:
     * the customer's), eur_rate (string|null; required for PLN/USD unless reverse charge),
     * issue_date/service_date/due_date ('Y-m-d'|null), valid_until ('Y-m-d'|null, offers only,
     * >= issue_date), intro_text/outro_text (null = company
     * default text), notes, lines (1..200 arrays: name, description, unit, qty_milli,
     * unit_minor, discount_bp, vat_bp, item_id). Allowed for suspended companies too.
     *
     * @param array<string, mixed> $data
     * @throws ValidationException|\InvalidArgumentException for a type outside DRAFT_TYPES
     */
    public function createDraft(string $type, array $data): int
    {
        return $this->db->transaction(fn (): int => $this->insertNewDraft($type, $data, null));
    }

    /**
     * The one draft insert. `related_document_id` is an INTERNAL argument (convertOffer): it never
     * comes from `$data`, which is read only through INPUT_FIELDS. Call inside a transaction.
     *
     * @param array<string, mixed> $data
     */
    private function insertNewDraft(string $type, array $data, ?int $relatedId): int
    {
        if (!in_array($type, self::DRAFT_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported draft type.');
        }
        $company = $this->docs->company();
        $in = ['customer_id' => null, 'lang' => null, 'currency' => null, 'eur_rate' => null, 'issue_date' => null,
            'service_date' => null, 'due_date' => null, 'valid_until' => null, 'notes' => null, 'lines' => null,
            'intro_text' => $company['intro_text'] ?? null, 'outro_text' => $company['outro_text'] ?? null];
        foreach (self::INPUT_FIELDS as $f) {
            if (array_key_exists($f, $data) && !(in_array($f, ['intro_text', 'outro_text'], true) && $data[$f] === null)) {
                $in[$f] = $data[$f];
            }
        }
        [$row, $lines] = $this->prepare($type, $in, null);
        $now = $this->nowUtc();
        $id = $this->docs->insertDraft(['type' => $type, 'related_document_id' => $relatedId] + $row + [
            'created_by' => $this->ctx->userId(), 'created_at' => $now,
        ]);
        $this->docs->replaceLines($id, $lines);
        return $id;
    }

    /**
     * Updates a draft; keys missing from $data keep their stored value (lines too). For credit
     * notes customer, currency, lang, eur_rate and the link to the invoice cannot change.
     *
     * @param array<string, mixed> $data
     * @throws DocumentNotFound|DocumentLocked|ValidationException
     */
    public function updateDraft(int $id, array $data): void
    {
        $this->db->transaction(function () use ($id, $data): void {
            $doc = $this->lockedDraft($id);
            if ($doc['type'] === DocType::CREDIT_NOTE) {
                foreach (self::CREDIT_NOTE_FIXED as $f) {
                    if (array_key_exists($f, $data) && self::loose($data[$f]) !== self::loose($doc[$f])) {
                        throw new ValidationException([$f => 'document.err_correction_fixed']);
                    }
                }
            }
            $in = [];
            foreach (self::INPUT_FIELDS as $f) {
                $in[$f] = array_key_exists($f, $data) ? $data[$f] : ($f === 'lines' ? $this->lineInput($id) : $doc[$f]);
            }
            [$row, $lines] = $this->prepare((string) $doc['type'], $in, $doc);
            $this->docs->updateDraftRow($id, $row);
            $this->docs->replaceLines($id, $lines);
        });
    }

    /**
     * Deletes a draft and its lines. Drafts have no number, so no counter is touched.
     *
     * @throws DocumentNotFound|DocumentLocked
     */
    public function deleteDraft(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->lockedDraft($id);
            $this->docs->deleteDraft($id);
        });
    }

    // ---- issuing ----

    /**
     * Issues a draft at $at and returns its number. All in one transaction; on any failure
     * nothing is written and the counter is not consumed.
     *
     * @throws DocumentNotFound|DocumentLocked|AccessSuspended|VatNeedsConfirmation|ValidationException
     * @throws \RuntimeException when no free number is found within 50 attempts
     */
    public function issue(int $id, \DateTimeImmutable $at, bool $confirmUnverifiedVat = false): string
    {
        // Plain pre-read OUTSIDE the transaction (see SNAPSHOTS in the class docblock): it only
        // tells the type and, for a credit note, the invoice to lock first (never changes).
        $pre = $this->docs->row($id) ?? throw new DocumentNotFound();
        return $this->db->transaction(function () use ($id, $at, $confirmUnverifiedVat, $pre): string {
            // Lock order (class docblock): a credit note's invoice BEFORE the credit note itself.
            // No plain SELECT may run in this transaction before the first lock.
            $invoice = null;
            if ($pre['type'] === DocType::CREDIT_NOTE) {
                $invoice = $this->docs->lockedFind((int) $pre['related_document_id']);
            }
            $doc = $this->lockedDraft($id);
            if ($doc['type'] !== $pre['type'] || $doc['related_document_id'] !== $pre['related_document_id']) {
                throw new DocumentLocked(); // changed between the plain read and the lock
            }
            if (!$this->ctx->canIssue($at)) {
                throw new AccessSuspended();
            }
            $type = (string) $doc['type'];
            if (!in_array($type, [DocType::INVOICE, DocType::OFFER, DocType::CREDIT_NOTE], true)) {
                throw new \LogicException('This document type is not issued here.');
            }
            $lines = $this->docs->lines($id);
            if ($lines === []) {
                throw new ValidationException(['lines' => 'document.err_lines']);
            }
            $today = $this->berlin($at)->format('Y-m-d');
            $issueDate = $doc['issue_date'] ?? $today;
            if ($issueDate > $today) {
                throw new ValidationException(['issue_date' => 'document.err_issue_future']);
            }
            $isOffer = $type === DocType::OFFER;
            $dueDate = $isOffer ? null : $doc['due_date']; // offers have no payment terms
            // § 14 Abs. 4 Nr. 6 UStG: the supply date is mandatory on an invoice; empty = the issue date
            // (the document then says so, see DocumentPresenter 'service_same'). Offers carry none.
            $serviceDate = $isOffer ? $doc['service_date'] : ($doc['service_date'] ?? $issueDate);
            $confirmed = false;

            if ($type === DocType::CREDIT_NOTE) {
                if ($invoice === null || $invoice['type'] !== DocType::INVOICE || $invoice['status'] !== 'issued') {
                    throw new ValidationException(['document' => 'document.err_correction_not_allowed']);
                }
                if ($issueDate < (string) $invoice['issue_date']) {
                    throw new ValidationException(['issue_date' => 'document.err_issue_before_invoice']);
                }
                $buyer = $invoice['buyer'];
                $buyerName = $invoice['buyer_name'];
                $reverseCharge = $invoice['reverse_charge'] === 1;
                $noteKey = $invoice['vat_note_key'];
                $reason = $invoice['vat_reason'];
            } else {
                $customer = $doc['customer_id'] === null ? null : $this->customers->find((int) $doc['customer_id']);
                if ($customer === null) {
                    throw new ValidationException(['customer_id' => 'document.err_customer']);
                }
                $buyer = self::buyerSnapshot($customer);
                if (!self::validUtf8($buyer)) {
                    throw new ValidationException(['customer_id' => 'document.err_encoding']);
                }
                $buyerName = (string) $customer['name'];
                $decision = VatRule::decide(VatRule::buyerFromCustomerRow($customer));
                // R30: an offer is no tax invoice, so an unconfirmed VIES result never blocks it
                // (it is issued with German VAT and the reason stays recorded).
                if ($decision->needsConfirmation && !$isOffer) {
                    if (!$confirmUnverifiedVat) {
                        throw new VatNeedsConfirmation($decision);
                    }
                    $confirmed = true;
                }
                $reverseCharge = $decision->reverseCharge;
                $noteKey = $decision->noteKey;
                $reason = $decision->reason;
                if ($dueDate === null && $type === DocType::INVOICE) {
                    // customers.payment_days is NOT NULL (defaulted from the company when the
                    // customer was created), so no company fallback is needed here.
                    $days = (int) $customer['payment_days'];
                    $dueDate = (new \DateTimeImmutable($issueDate))->modify("+{$days} days")->format('Y-m-d');
                }
            }
            if ($dueDate !== null && $dueDate < $issueDate) {
                throw new ValidationException(['due_date' => 'document.err_due_before_issue']);
            }
            $validUntil = $doc['valid_until'];
            if ($isOffer && $validUntil === null) {
                $validUntil = (new \DateTimeImmutable($issueDate))->modify('+' . self::OFFER_VALID_DAYS . ' days')->format('Y-m-d');
            }
            if ($validUntil !== null && $validUntil < $issueDate) {
                throw new ValidationException(['valid_until' => 'document.err_valid_until_before_issue']);
            }
            $seller = self::sellerSnapshot($this->docs->company());
            if (!self::validUtf8($seller)) {
                throw new ValidationException(['company' => 'document.err_encoding']);
            }
            self::assertMandatoryParties($seller, (array) $buyer, $reverseCharge, $isOffer);

            $totals = $this->computeTotals($lines, $reverseCharge, (string) $doc['currency'], $doc['eur_rate']);
            foreach ($lines as $l) {
                if (Totals::lineNet(self::toLine($l)) !== $l['net_minor']) {
                    throw new \LogicException('Stored line net does not match the calculation.');
                }
            }
            if ($invoice !== null) {
                $credited = $totals->grossMinor;
                foreach ($this->docs->issuedCreditNotes((int) $invoice['id'], true) as $cn) {
                    $credited += abs($cn['gross_minor']);
                }
                if ($credited > $invoice['gross_minor']) {
                    throw new ValidationException(['lines' => 'document.err_credit_exceeds']);
                }
            }

            $sign = $type === DocType::CREDIT_NOTE ? -1 : 1;
            $row = [
                'status' => 'issued',
                'issued_at' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'issue_date' => $issueDate,
                'service_date' => $serviceDate,
                'due_date' => $dueDate,
                'seller_json' => self::json($seller),
                'buyer_json' => self::json($buyer),
                'buyer_name' => $buyerName,
                'reverse_charge' => $reverseCharge ? 1 : 0,
                'vat_note_key' => $reverseCharge ? $noteKey : null,
                'vat_reason' => $reason,
                'updated_at' => $this->nowUtc(),
            ] + ($isOffer ? ['valid_until' => $validUntil, 'offer_status' => self::OFFER_OPEN] : [])
                + self::signedTotals($totals, $sign);

            $number = $this->writeWithNumber($id, $type, new \DateTimeImmutable($issueDate, new \DateTimeZone('Europe/Berlin')), $row);
            $this->audit('document.issue', [
                'document_id' => $id, 'type' => $type, 'number' => $number, 'vat_confirmed' => $confirmed,
            ]);
            return $number;
        });
    }

    // ---- payments ----

    /**
     * Records a payment for an issued invoice. 0 < amount <= balance; paid on or after the
     * issue date. The balance is re-checked under a locking read of the invoice.
     *
     * @throws DocumentNotFound|ValidationException
     */
    public function addPayment(int $docId, int $amountMinor, \DateTimeImmutable $on, ?string $note = null): void
    {
        $this->db->transaction(function () use ($docId, $amountMinor, $on, $note): void {
            $doc = $this->docs->lockedFind($docId) ?? throw new DocumentNotFound();
            if ($doc['type'] !== DocType::INVOICE || $doc['status'] !== 'issued') {
                throw new ValidationException(['document' => 'document.err_payment_not_allowed']);
            }
            $errors = [];
            if ($amountMinor <= 0) {
                $errors['amount'] = 'document.err_amount';
            }
            $paidOn = $this->berlin($on)->format('Y-m-d');
            if ($paidOn < (string) $doc['issue_date']) {
                $errors['paid_on'] = 'document.err_paid_on';
            } elseif ($paidOn > $this->berlinToday()) {
                $errors['paid_on'] = 'document.err_paid_on_future'; // M6: a payment cannot have happened yet
            }
            $note = $note === null ? '' : trim(str_replace(["\r", "\n"], ' ', $note));
            if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note, 'UTF-8') > self::NOTE_MAX) {
                $errors['note'] = 'document.err_note';
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            if ($amountMinor > $this->summary($doc, true, null)['balance_minor']) {
                throw new ValidationException(['amount' => 'document.err_overpayment']);
            }
            $paymentId = $this->docs->insertPayment([
                'document_id' => $docId, 'amount_minor' => $amountMinor, 'paid_on' => $paidOn,
                'note' => $note === '' ? null : $note, 'created_by' => $this->ctx->userId(), 'created_at' => $this->nowUtc(),
            ]);
            $this->audit('document.payment_add', ['document_id' => $docId, 'payment_id' => $paymentId]);
        });
    }

    /**
     * Reverses ("Storno") one payment of an issued invoice: inserts a NEGATIVE payment of exactly the
     * original amount that points at it (reverses_payment_id). Nothing is updated or deleted, every
     * sum over the payments is the net sum, so the invoice is re-opened when the net drops below the
     * total. Each payment can be reversed once (checked under the invoice lock and by a UNIQUE index),
     * a reversal row itself cannot be reversed, the date lies between the original payment and today.
     *
     * @return int id of the reversal row
     * @throws DocumentNotFound unknown or another company's invoice
     * @throws ValidationException document (not an issued invoice), payment (unknown, of another document,
     *         itself a reversal or already reversed), paid_on, note
     */
    public function reversePayment(int $docId, int $paymentId, \DateTimeImmutable $on, ?string $note = null): int
    {
        return $this->db->transaction(function () use ($docId, $paymentId, $on, $note): int {
            $doc = $this->docs->lockedFind($docId) ?? throw new DocumentNotFound();
            if ($doc['type'] !== DocType::INVOICE || $doc['status'] !== 'issued') {
                throw new ValidationException(['document' => 'document.err_payment_not_allowed']);
            }
            $original = null;
            $reversed = false;
            foreach ($this->docs->payments($docId, true) as $p) {
                if ($p['id'] === $paymentId) {
                    $original = $p;
                }
                if ($p['reverses_payment_id'] === $paymentId) {
                    $reversed = true;
                }
            }
            if ($original === null || $original['amount_minor'] <= 0 || $original['reverses_payment_id'] !== null || $reversed) {
                throw new ValidationException(['payment' => 'document.err_reverse_not_allowed']);
            }
            $errors = [];
            $paidOn = $this->berlin($on)->format('Y-m-d');
            if ($paidOn < (string) $original['paid_on']) {
                $errors['paid_on'] = 'document.err_paid_on';
            } elseif ($paidOn > $this->berlinToday()) {
                $errors['paid_on'] = 'document.err_paid_on_future';
            }
            $note = $note === null ? '' : trim(str_replace(["\r", "\n"], ' ', $note));
            if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note, 'UTF-8') > self::NOTE_MAX) {
                $errors['note'] = 'document.err_note';
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            $reversalId = $this->docs->insertPayment([
                'document_id' => $docId, 'amount_minor' => -$original['amount_minor'], 'paid_on' => $paidOn,
                'note' => $note === '' ? null : $note, 'reverses_payment_id' => $paymentId,
                'created_by' => $this->ctx->userId(), 'created_at' => $this->nowUtc(),
            ]);
            $this->audit('document.payment_reverse', ['document_id' => $docId, 'payment_id' => $paymentId, 'reversal_id' => $reversalId]);
            return $reversalId;
        });
    }

    /** gross - payments - issued credit notes, never negative. @throws DocumentNotFound */
    public function balanceMinor(int $docId): int
    {
        return $this->paymentSummary($docId, $this->berlin(new \DateTimeImmutable('@' . ($this->clock)())))['balance_minor'];
    }

    /** 'draft'|'open'|'partial'|'overdue'|'paid'|'credited' (see paymentSummary). @throws DocumentNotFound */
    public function paymentStatus(int $docId, \DateTimeImmutable $today): string
    {
        return $this->paymentSummary($docId, $today)['status'];
    }

    /**
     * Payment state of a document on the Berlin day of $today.
     * Invoices: draft (not issued) | credited (issued credit notes cover the gross) | paid
     * (balance 0) | overdue (due_date before today; wins over partial) | partial | open.
     * Other types: 'draft' or 'issued', with zero paid/credited/balance.
     *
     * @return array{status: string, gross_minor: int, paid_minor: int, credited_minor: int, balance_minor: int, due_date: ?string}
     * @throws DocumentNotFound
     */
    public function paymentSummary(int $docId, \DateTimeImmutable $today): array
    {
        $doc = $this->docs->find($docId) ?? throw new DocumentNotFound();
        return $this->summary($doc, false, $this->berlin($today)->format('Y-m-d'));
    }

    // ---- corrections ----

    /**
     * Creates a DRAFT credit note for an issued invoice: same customer, currency, language,
     * exchange rate and VAT treatment, the invoice's lines copied as positive lines (edit them
     * with updateDraft for a partial correction).
     *
     * @throws DocumentNotFound|ValidationException
     */
    public function createCorrection(int $invoiceId): int
    {
        return $this->db->transaction(function () use ($invoiceId): int {
            $inv = $this->docs->lockedFind($invoiceId) ?? throw new DocumentNotFound();
            if ($inv['type'] !== DocType::INVOICE || $inv['status'] !== 'issued') {
                throw new ValidationException(['document' => 'document.err_correction_not_allowed']);
            }
            $lines = [];
            foreach ($this->docs->lines($invoiceId) as $l) {
                $lines[] = array_intersect_key($l, array_flip(['item_id', 'name', 'description', 'unit', 'qty_milli',
                    'unit_minor', 'discount_bp', 'vat_bp', 'net_minor']));
            }
            $totals = $this->computeTotals($lines, $inv['reverse_charge'] === 1, (string) $inv['currency'], $inv['eur_rate']);
            $now = $this->nowUtc();
            $id = $this->docs->insertDraft([
                'type' => DocType::CREDIT_NOTE,
                'related_document_id' => $invoiceId,
                'customer_id' => $inv['customer_id'],
                'lang' => $inv['lang'],
                'currency' => $inv['currency'],
                'eur_rate' => $inv['eur_rate'],
                'reverse_charge' => $inv['reverse_charge'],
                'vat_note_key' => $inv['vat_note_key'],
                'vat_reason' => $inv['vat_reason'],
                'service_date' => $inv['service_date'],
                'buyer_name' => $inv['buyer_name'],
                'created_by' => $this->ctx->userId(),
                'created_at' => $now,
                'updated_at' => $now,
            ] + self::signedTotals($totals, -1));
            $this->docs->replaceLines($id, $lines);
            $this->audit('document.correction_create', ['document_id' => $id, 'invoice_id' => $invoiceId]);
            return $id;
        });
    }

    /**
     * Unsigned totals recomputed from the document's stored lines (as returned by
     * DocumentRepo::find). Stored totals equal these times the sign (-1 for credit notes).
     *
     * @param array<string, mixed> $document
     */
    public function totalsFor(array $document): Totals
    {
        return Totals::compute(
            array_map(self::toLine(...), $document['lines']),
            (int) $document['reverse_charge'] === 1,
            (string) $document['currency'],
            $document['eur_rate'] === null ? null : (string) $document['eur_rate'],
        );
    }

    // ---- offers ----

    /**
     * Sets the decision status of an ISSUED offer: open | sent | accepted | rejected (any to any,
     * except that an offer with a linked invoice, draft or issued, cannot leave 'accepted').
     * Writes an audit row (ids and both statuses). Allowed for suspended companies too.
     *
     * @throws DocumentNotFound unknown, another company's document or not an offer
     * @throws ValidationException status (unknown value | document.err_offer_converted) or document (draft)
     */
    public function setOfferStatus(int $id, string $status): void
    {
        $this->db->transaction(function () use ($id, $status): void {
            $this->changeOfferStatus($id, $status, null);
        });
    }

    /**
     * Bookkeeping after an offer email went out: 'open' becomes 'sent'; any other state stays.
     * Never throws for a state that does not fit (DocumentNotFound for a foreign/unknown id only).
     *
     * @throws DocumentNotFound
     */
    public function markOfferSent(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->changeOfferStatus($id, 'sent', self::OFFER_OPEN);
        });
    }

    /**
     * draft | open | sent | accepted | rejected | expired. 'expired' is computed: a stored open
     * or sent status whose valid_until lies before the Berlin date of $today (valid until today
     * is still valid). Accepted and rejected offers never expire.
     *
     * @param array<string, mixed> $document a DocumentRepo row of an offer
     */
    public function offerStatus(array $document, \DateTimeImmutable $today): string
    {
        return OfferStatus::of($document, $today);
    }

    /**
     * Creates a DRAFT invoice from an ISSUED, ACCEPTED offer (also after valid_until: the offer was
     * accepted). Copies customer, language, currency, exchange rate and all lines as new rows and
     * links the invoice to the offer (related_document_id). The texts are the company defaults;
     * notes and dates stay empty (issue date at issue, due date from the payment terms). Totals
     * and VAT treatment are decided like for any draft (VatRule on the customer as of now).
     *
     * Double-click protection: when a DRAFT invoice for this offer already exists, its id is
     * returned and nothing is created. After the first invoice was issued a further draft can be
     * created (partial billing). One transaction under the offer's row lock, so two parallel
     * requests cannot both create a draft.
     *
     * @throws DocumentNotFound unknown, another company's document or not an offer
     * @throws ValidationException document (draft or not accepted) or the draft's own errors
     */
    public function convertOffer(int $offerId): int
    {
        return $this->db->transaction(function () use ($offerId): int {
            $offer = $this->docs->lockedFind($offerId) ?? throw new DocumentNotFound();
            if ($offer['type'] !== DocType::OFFER) {
                throw new DocumentNotFound();
            }
            if ($offer['status'] !== 'issued' || $offer['offer_status'] !== 'accepted') {
                throw new ValidationException(['document' => 'document.err_offer_not_accepted']);
            }
            $existing = $this->docs->list(['type' => DocType::INVOICE, 'status' => 'draft', 'related_document_id' => $offerId], 1);
            if ($existing !== []) {
                return (int) $existing[0]['id'];
            }
            $id = $this->insertNewDraft(DocType::INVOICE, [
                'customer_id' => $offer['customer_id'], 'lang' => $offer['lang'], 'currency' => $offer['currency'],
                'eur_rate' => $offer['eur_rate'], 'lines' => $this->lineInput($offerId),
            ], $offerId);
            $this->audit('document.offer_convert', ['offer_id' => $offerId, 'invoice_id' => $id]);
            return $id;
        });
    }

    /** $onlyFrom: change only when the stored status is this one (otherwise nothing happens). */
    private function changeOfferStatus(int $id, string $status, ?string $onlyFrom): void
    {
        if (!in_array($status, self::OFFER_STATUSES, true)) {
            throw new ValidationException(['status' => 'document.err_offer_status']);
        }
        $offer = $this->docs->lockedFind($id) ?? throw new DocumentNotFound();
        if ($offer['type'] !== DocType::OFFER) {
            throw new DocumentNotFound();
        }
        if ($offer['status'] !== 'issued') {
            if ($onlyFrom !== null) {
                return;
            }
            throw new ValidationException(['document' => 'document.err_offer_draft_status']);
        }
        $from = (string) ($offer['offer_status'] ?? self::OFFER_OPEN);
        if (($onlyFrom !== null && $from !== $onlyFrom) || $from === $status) {
            return;
        }
        if ($from === 'accepted' && $this->docs->count(['type' => DocType::INVOICE, 'related_document_id' => $id]) > 0) {
            if ($onlyFrom !== null) {
                return;
            }
            throw new ValidationException(['status' => 'document.err_offer_converted']);
        }
        $this->docs->updateOfferStatus($id, $status, $this->nowUtc());
        $this->audit('document.offer_status', ['document_id' => $id, 'from' => $from, 'to' => $status]);
    }

    // ---- dunning letters (Task 21, R33) ----

    /**
     * What can be dunned for an ISSUED invoice right now (plain reads, no side effects): the
     * invoice facts, the OUTSTANDING balance (gross - payments - issued credit notes), days overdue
     * on the Berlin date of $at, the last issued dunning level, the levels still allowed (strictly
     * higher), the suggestion (Dunning::suggest) and which extras are available.
     *
     * @return array<string, mixed> keys: invoice_id, number, invoice_date, due_date, currency,
     *   lang, buyer_name, is_company, gross_minor, paid_minor, credited_minor, outstanding_minor,
     *   days_overdue, last_level (?DunningLevel), last_date (?string), levels (list<DunningLevel>),
     *   suggested (?DunningLevel), base_rate_set, interest_rate_bp (?int, annual rate for this buyer),
     *   flat_fee_allowed, flat_fee_setting_minor, fee_allowed, fee_setting_minor, can_issue, today ('Y-m-d'), pay_by,
     *   settings (DunningSettings)
     * @throws DocumentNotFound unknown id, another company's document, not an invoice
     * @throws ValidationException ['invoice' => dunning.err_not_issued | err_credited | err_paid | err_not_overdue | err_final_reached]
     */
    public function dunningContext(int $invoiceId, ?\DateTimeImmutable $at = null): array
    {
        $invoice = $this->docs->row($invoiceId) ?? throw new DocumentNotFound();
        $base = $this->dunningBase($invoice, false, $at ?? $this->clockNow());
        if ($base['levels'] === []) {
            throw new ValidationException(['invoice' => 'dunning.err_final_reached']);
        }
        return $base;
    }

    /**
     * The dunning letter createDunning() would store for these inputs now, without writing anything
     * (the on-screen preview). Same checks and the same computation as createDunning().
     *
     * @return array<string, mixed> dunningContext() plus level, interest_minor, interest_days,
     *   interest_rate_bp, flat_fee_minor, fee_minor (each null when not charged), claim_minor, issue_date
     * @throws DocumentNotFound|ValidationException as dunningContext(), plus level | interest | flat_fee | fee | invoice (err_overflow)
     */
    public function dunningQuote(int $invoiceId, DunningLevel $level, bool $withInterest, bool $withFlatFee, bool $withFee, ?\DateTimeImmutable $at = null): array
    {
        $base = $this->dunningContext($invoiceId, $at);
        return $this->dunningAmounts($base, $level, $withInterest, $withFlatFee, $withFee) + $base;
    }

    /**
     * Creates AND issues a dunning letter for an overdue invoice (R33): numbered from the 'dunning'
     * sequence, immutable, buyer snapshot copied from the invoice, seller snapshot = company data
     * now, amounts in the dunning_* columns (net/vat/gross stay 0, so no revenue report counts it).
     * Interest, flat fee and fee only when their flag is true AND they are allowed; never silently.
     * Nothing is sent here. Returns the new document id.
     *
     * ONE transaction: the invoice row is locked FIRST (the first statement), then its payments,
     * credit notes and dunning letters are read with locking reads, every check runs (no counter is
     * consumed by a refusal), then the new document row is written and NumberSequences::next draws
     * the number (R24 collision retry as for every issue).
     *
     * @throws DocumentNotFound|AccessSuspended|ValidationException (see dunningQuote)
     */
    public function createDunning(int $invoiceId, DunningLevel $level, bool $withInterest, bool $withFlatFee, bool $withFee, ?\DateTimeImmutable $at = null): int
    {
        $at ??= $this->clockNow();
        return $this->db->transaction(function () use ($invoiceId, $level, $withInterest, $withFlatFee, $withFee, $at): int {
            $invoice = $this->docs->lockedFind($invoiceId) ?? throw new DocumentNotFound();
            if ($invoice['type'] !== DocType::INVOICE) {
                throw new DocumentNotFound();
            }
            if (!$this->ctx->canIssue($at)) {
                throw new AccessSuspended();
            }
            $base = $this->dunningBase($invoice, true, $at);
            $q = $this->dunningAmounts($base, $level, $withInterest, $withFlatFee, $withFee);
            $seller = self::sellerSnapshot($this->docs->company());
            if (!self::validUtf8($seller)) {
                throw new ValidationException(['company' => 'document.err_encoding']);
            }
            // R30-style relaxed gate (a dunning letter is no tax invoice): both names.
            self::assertMandatoryParties($seller, (array) $invoice['buyer'], false, true);

            $now = $this->nowUtc();
            $id = $this->docs->insertDraft([
                'type' => DocType::DUNNING, 'related_document_id' => $invoiceId, 'customer_id' => $invoice['customer_id'],
                'lang' => $invoice['lang'], 'currency' => $invoice['currency'], 'reverse_charge' => 0, 'buyer_name' => $invoice['buyer_name'],
                'net_minor' => 0, 'vat_minor' => 0, 'gross_minor' => 0, 'created_by' => $this->ctx->userId(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $number = $this->writeWithNumber($id, DocType::DUNNING, new \DateTimeImmutable($base['today'], new \DateTimeZone('Europe/Berlin')), [
                'status' => 'issued',
                'issued_at' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'issue_date' => $base['today'],
                'seller_json' => self::json($seller),
                'buyer_json' => self::json((array) $invoice['buyer']),
                'dunning_level' => $level->value,
                'dunning_outstanding_minor' => $base['outstanding_minor'],
                'dunning_interest_minor' => $q['interest_minor'],
                'dunning_interest_days' => $q['interest_days'],
                'dunning_interest_rate_bp' => $q['interest_rate_bp'],
                'dunning_flat_fee_minor' => $q['flat_fee_minor'],
                'dunning_fee_minor' => $q['fee_minor'],
                'dunning_claim_minor' => $q['claim_minor'],
                'dunning_pay_by' => $base['pay_by'],
                'updated_at' => $now,
            ]);
            $this->audit('document.dunning_create', ['document_id' => $id, 'invoice_id' => $invoiceId, 'level' => $level->value,
                'interest' => $withInterest, 'flat_fee' => $withFlatFee, 'fee' => $withFee]);
            return $id;
        });
    }

    /**
     * Eligibility and facts of an invoice for dunning (see dunningContext). With $lock the scans run
     * as locking reads (inside createDunning's transaction, after the invoice lock).
     *
     * @param array<string, mixed> $invoice document row
     * @return array<string, mixed>
     */
    private function dunningBase(array $invoice, bool $lock, \DateTimeImmutable $at): array
    {
        if ($invoice['type'] !== DocType::INVOICE) {
            throw new DocumentNotFound();
        }
        if ($invoice['status'] !== 'issued') {
            throw new ValidationException(['invoice' => 'dunning.err_not_issued']);
        }
        $id = (int) $invoice['id'];
        $summary = $this->summary($invoice, $lock, null);
        $last = null;
        $lastDate = null;
        foreach ($this->docs->issuedDunnings($id, $lock) as $d) {
            $lvl = DunningLevel::tryFrom((int) $d['dunning_level']);
            if ($lvl !== null && ($last === null || $lvl->value >= $last->value)) {
                $last = $lvl;
                $lastDate = $d['issue_date'];
            }
        }
        $today = $this->berlin($at)->format('Y-m-d');
        $days = $invoice['due_date'] === null ? 0 : Dunning::daysOverdue((string) $invoice['due_date'], $at);
        if ($summary['credited_minor'] > 0 && $summary['credited_minor'] >= $invoice['gross_minor']) {
            throw new ValidationException(['invoice' => 'dunning.err_credited']);
        }
        if ($summary['balance_minor'] <= 0) {
            throw new ValidationException(['invoice' => 'dunning.err_paid']);
        }
        if ($days < 1) {
            throw new ValidationException(['invoice' => 'dunning.err_not_overdue']);
        }
        // Empty after the final level: createDunning() then refuses any level (err_level_not_higher).
        $levels = array_values(array_filter(DunningLevel::cases(), fn (DunningLevel $l): bool => $last === null || $l->value > $last->value));
        $settings = DunningSettings::fromCompanyRow($this->docs->company());
        $isCompany = (int) (((array) $invoice['buyer'])['is_company'] ?? 0) === 1;
        $currency = (string) $invoice['currency'];
        $feesAllowed = Dunning::feesAllowedFor($currency);
        return [
            'invoice_id' => $id,
            'number' => (string) $invoice['number'],
            'invoice_date' => (string) $invoice['issue_date'],
            'due_date' => (string) $invoice['due_date'],
            'currency' => $currency,
            'lang' => (string) $invoice['lang'],
            'buyer_name' => (string) ($invoice['buyer_name'] ?? ''),
            'is_company' => $isCompany,
            'gross_minor' => (int) $invoice['gross_minor'],
            'paid_minor' => $summary['paid_minor'],
            'credited_minor' => $summary['credited_minor'],
            'outstanding_minor' => $summary['balance_minor'],
            'days_overdue' => $days,
            'last_level' => $last,
            'last_date' => $lastDate,
            'levels' => $levels,
            'suggested' => Dunning::suggest($days, $last, $settings),
            'settings' => $settings,
            'base_rate_set' => $settings->baseRateBp !== null,
            'interest_rate_bp' => Dunning::annualRateBp($settings, $isCompany),
            'flat_fee_allowed' => $isCompany && $feesAllowed,
            'flat_fee_setting_minor' => $settings->flatMinor,
            'fee_allowed' => $feesAllowed && $settings->feeMinor > 0,
            'fee_setting_minor' => $settings->feeMinor,
            'can_issue' => $this->ctx->canIssue($at),
            'today' => $today,
            'pay_by' => (new \DateTimeImmutable($today))->modify('+' . self::DUNNING_PAY_DAYS . ' days')->format('Y-m-d'),
        ];
    }

    /**
     * The amounts of a dunning letter at $level from dunningBase(): interest from the OUTSTANDING
     * balance for the days overdue on the issue date, flat fee / fee only when flagged AND allowed.
     *
     * @param array<string, mixed> $base
     * @return array{level: DunningLevel, interest_minor: ?int, interest_days: ?int, interest_rate_bp: ?int, flat_fee_minor: ?int, fee_minor: ?int, claim_minor: int, issue_date: string}
     * @throws ValidationException
     */
    private function dunningAmounts(array $base, DunningLevel $level, bool $withInterest, bool $withFlatFee, bool $withFee): array
    {
        if (!in_array($level, $base['levels'], true)) {
            throw new ValidationException(['level' => 'dunning.err_level_not_higher']);
        }
        /** @var DunningSettings $s */
        $s = $base['settings'];
        $errors = [];
        if ($withInterest && !$base['base_rate_set']) {
            $errors['interest'] = 'dunning.err_no_base_rate';
        }
        if ($withFlatFee && !$base['flat_fee_allowed']) {
            $errors['flat_fee'] = 'dunning.err_flat_fee_not_allowed';
        }
        if ($withFee && !$base['fee_allowed']) {
            $errors['fee'] = 'dunning.err_fee_not_allowed';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $currency = (string) $base['currency'];
        try {
            $outstanding = Money::of((int) $base['outstanding_minor'], $currency);
            $interest = null;
            if ($withInterest) {
                $surcharge = $base['is_company'] ? $s->b2bSurchargeBp : $s->b2cSurchargeBp;
                $rate = $s->baseRateBp + $surcharge;
                if (!is_int($rate)) {
                    throw new \OverflowException('Rate overflows.');
                }
                $interest = Dunning::interest($outstanding, (int) $base['days_overdue'], $s->baseRateBp, $surcharge);
            }
            $flat = $withFlatFee ? Dunning::flatFee(true, $s) : null;
            $fee = $withFee ? Dunning::fee($s) : null;
            $claim = Dunning::claimTotal($outstanding, $interest, $flat, $fee);
        } catch (\OverflowException) {
            throw new ValidationException(['invoice' => 'dunning.err_overflow']);
        }
        return [
            'level' => $level,
            'interest_minor' => $interest?->minor(),
            'interest_days' => $interest === null ? null : (int) $base['days_overdue'],
            'interest_rate_bp' => $interest === null ? null : $base['interest_rate_bp'],
            'flat_fee_minor' => $flat?->minor(),
            'fee_minor' => $fee?->minor(),
            'claim_minor' => $claim->minor(),
            'issue_date' => (string) $base['today'],
        ];
    }

    /** Today's Berlin calendar date by the service clock ('Y-m-d'). */
    private function berlinToday(): string
    {
        return $this->berlin($this->clockNow())->format('Y-m-d');
    }

    private function clockNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . ($this->clock)());
    }

    // ---- internals ----

    /** @return array<string, mixed> */
    private function lockedDraft(int $id): array
    {
        $doc = $this->docs->lockedFind($id) ?? throw new DocumentNotFound();
        if ($doc['status'] !== 'draft') {
            throw new DocumentLocked();
        }
        return $doc;
    }

    /**
     * Draws numbers until one is free (R24: a pattern change can make the counter produce a
     * number that is already used) and writes the issued row with it. A collision found by the
     * locking check or raised by the unique index (SQLSTATE class 23) just draws again.
     *
     * @param array<string, mixed> $row
     */
    private function writeWithNumber(int $id, string $type, \DateTimeImmutable $date, array $row): string
    {
        for ($i = 0; $i < self::NUMBER_ATTEMPTS; $i++) {
            $number = $this->numbers->next($type, $date);
            if ($this->docs->numberTaken($type, $number)) {
                continue;
            }
            try {
                $this->docs->updateDraftRow($id, $row + ['number' => $number]);
                return $number;
            } catch (\PDOException $e) {
                if (!str_starts_with((string) ($e->errorInfo[0] ?? $e->getCode()), '23')) {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('No free document number found.');
    }

    /**
     * Validates draft input and computes the row to store (with signed totals) and the lines.
     *
     * @param array<string, mixed> $in all INPUT_FIELDS
     * @param array<string, mixed>|null $doc the stored draft on update
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     * @throws ValidationException
     */
    private function prepare(string $type, array $in, ?array $doc): array
    {
        $e = [];
        $customerId = self::intOf($in['customer_id']);
        $customer = $customerId === null ? null : $this->customers->find($customerId);
        if ($customer === null) {
            $e['customer_id'] = 'document.err_customer';
        }
        // Defaults come from the customer; without a valid customer only explicit values are checked.
        $lang = $in['lang'] ?? $customer['default_lang'] ?? null;
        if (!in_array($lang, self::LANGS, true) && ($lang !== null || $customer !== null)) {
            $e['lang'] = 'document.err_lang';
        }
        $currency = $in['currency'] ?? $customer['default_currency'] ?? null;
        if (!in_array($currency, self::CURRENCIES, true) && ($currency !== null || $customer !== null)) {
            $e['currency'] = 'document.err_currency';
        }
        $rate = $in['eur_rate'] === null || $in['eur_rate'] === '' ? null : $in['eur_rate'];
        if ($rate !== null) {
            if (!is_string($rate) || $currency === 'EUR') {
                $e['eur_rate'] = 'document.err_eur_rate';
            } else {
                try {
                    Totals::parseRate($rate);
                } catch (\InvalidArgumentException) {
                    $e['eur_rate'] = 'document.err_eur_rate';
                }
            }
        }
        $dates = [];
        foreach (self::DATE_FIELDS as $f) {
            $dates[$f] = null;
            if ($in[$f] !== null && $in[$f] !== '') {
                $dates[$f] = self::dateOf($in[$f]);
                if ($dates[$f] === null) {
                    $e[$f] = 'document.err_date';
                }
            }
        }
        if ($type === DocType::OFFER) {
            $dates['due_date'] = null; // offers carry no payment terms: a posted due date is ignored
            unset($e['due_date']);
        }
        if ($dates['issue_date'] !== null && $dates['due_date'] !== null && $dates['due_date'] < $dates['issue_date']) {
            $e['due_date'] = 'document.err_due_before_issue';
        }
        if ($type !== DocType::OFFER && $in['valid_until'] !== null && $in['valid_until'] !== '') {
            $e['valid_until'] = 'document.err_valid_until_offer_only';
        } elseif ($dates['issue_date'] !== null && $dates['valid_until'] !== null && $dates['valid_until'] < $dates['issue_date']) {
            $e['valid_until'] = 'document.err_valid_until_before_issue';
        }
        // A credit note takes its buyer from the invoice snapshot, not from the customer row.
        if ($type !== DocType::CREDIT_NOTE && $customer !== null && !self::validUtf8(self::buyerSnapshot($customer))) {
            $e['customer_id'] = 'document.err_encoding';
        }
        $texts = [];
        foreach (self::TEXT_FIELDS as $f) {
            $v = $in[$f];
            if ($v !== null && !is_scalar($v)) {
                $e[$f] = 'document.err_text';
                continue;
            }
            $v = trim(str_replace(["\r\n", "\r"], "\n", (string) $v));
            if (!mb_check_encoding($v, 'UTF-8')) {
                $e[$f] = 'document.err_encoding';
            } elseif (mb_strlen($v, 'UTF-8') > self::TEXT_MAX) {
                $e[$f] = 'document.err_text';
            }
            $texts[$f] = $v === '' ? null : $v;
        }
        [$lines, $lineErrors] = $this->validateLines($in['lines']);
        $e += $lineErrors;
        if ($e !== []) {
            throw new ValidationException($e);
        }

        if ($type === DocType::CREDIT_NOTE) {
            // VAT treatment follows the original invoice (copied at createCorrection).
            $reverseCharge = $doc['reverse_charge'] === 1;
            $noteKey = $doc['vat_note_key'];
            $reason = $doc['vat_reason'];
            $buyerName = $doc['buyer_name'];
        } else {
            $decision = VatRule::decide(VatRule::buyerFromCustomerRow($customer));
            // needsConfirmation: never a silent reverse charge, the draft carries German VAT.
            $reverseCharge = $decision->reverseCharge;
            $noteKey = $decision->noteKey;
            $reason = $decision->reason;
            $buyerName = (string) $customer['name'];
        }
        if ($currency !== 'EUR' && $rate === null && !$reverseCharge) {
            throw new ValidationException(['eur_rate' => 'document.err_eur_rate_required']);
        }
        $totals = $this->computeTotals($lines, $reverseCharge, $currency, $rate);
        foreach ($lines as $i => $l) {
            $lines[$i]['net_minor'] = Totals::lineNet(self::toLine($l));
        }
        $row = [
            'customer_id' => $customerId,
            'lang' => $lang,
            'currency' => $currency,
            'eur_rate' => $rate,
            'reverse_charge' => $reverseCharge ? 1 : 0,
            'vat_note_key' => $reverseCharge ? $noteKey : null,
            'vat_reason' => $reason,
            'buyer_name' => $buyerName,
            'updated_at' => $this->nowUtc(),
        ] + $dates + $texts + self::signedTotals($totals, $type === DocType::CREDIT_NOTE ? -1 : 1);
        return [$row, $lines];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function validateLines(mixed $lines): array
    {
        if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > self::MAX_LINES) {
            return [[], ['lines' => 'document.err_lines']];
        }
        $out = [];
        $e = [];
        foreach ($lines as $i => $l) {
            $p = "lines.$i.";
            if (!is_array($l)) {
                $e["lines.$i"] = 'document.err_lines';
                continue;
            }
            $name = is_scalar($l['name'] ?? null) ? trim((string) $l['name']) : '';
            if (!mb_check_encoding($name, 'UTF-8')) {
                $e[$p . 'name'] = 'document.err_encoding';
            } elseif ($name === '' || mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
                $e[$p . 'name'] = 'document.err_line_name';
            }
            $desc = $l['description'] ?? null;
            $desc = is_scalar($desc) ? trim(str_replace(["\r\n", "\r"], "\n", (string) $desc)) : '';
            if (!mb_check_encoding($desc, 'UTF-8')) {
                $e[$p . 'description'] = 'document.err_encoding';
            } elseif (mb_strlen($desc, 'UTF-8') > self::TEXT_MAX) {
                $e[$p . 'description'] = 'document.err_line_description';
            }
            $unit = $l['unit'] ?? null;
            if (!is_string($unit) || !Units::isValid($unit)) {
                $e[$p . 'unit'] = 'document.err_line_unit';
            }
            $qty = self::intOf($l['qty_milli'] ?? null);
            if ($qty === null || $qty <= 0 || $qty > self::QTY_MAX) {
                $e[$p . 'qty_milli'] = 'document.err_line_qty';
            }
            $price = self::intOf($l['unit_minor'] ?? null);
            if ($price === null || $price < 0 || $price > self::PRICE_MAX) {
                $e[$p . 'unit_minor'] = 'document.err_line_price';
            }
            $discount = self::intOf($l['discount_bp'] ?? 0);
            if ($discount === null || $discount < 0 || $discount > 10000) {
                $e[$p . 'discount_bp'] = 'document.err_line_discount';
            }
            $vat = self::intOf($l['vat_bp'] ?? null);
            if ($vat === null || !in_array($vat, Line::VAT_RATES_BP, true)) {
                $e[$p . 'vat_bp'] = 'document.err_line_vat';
            }
            $itemId = null;
            if (($l['item_id'] ?? null) !== null && ($l['item_id'] ?? null) !== '') {
                $itemId = self::intOf($l['item_id']);
                if ($itemId === null || !$this->docs->itemExists($itemId)) {
                    $e[$p . 'item_id'] = 'document.err_line_item';
                }
            }
            $out[] = [
                'item_id' => $itemId, 'name' => $name, 'description' => $desc === '' ? null : $desc, 'unit' => $unit,
                'qty_milli' => $qty, 'unit_minor' => $price, 'discount_bp' => $discount, 'vat_bp' => $vat,
            ];
        }
        return [$out, $e];
    }

    /**
     * @param list<array<string, mixed>> $lines validated lines
     * @throws ValidationException on overflow or a missing exchange rate
     */
    private function computeTotals(array $lines, bool $reverseCharge, string $currency, ?string $rate): Totals
    {
        try {
            return Totals::compute(array_map(self::toLine(...), $lines), $reverseCharge, $currency, $rate);
        } catch (\OverflowException) {
            throw new ValidationException(['lines' => 'document.err_total_overflow']);
        } catch (\InvalidArgumentException) {
            throw new ValidationException(['eur_rate' => 'document.err_eur_rate_required']);
        }
    }

    /**
     * Paid/credited/balance of a document row. With $lock the payments and credit notes are
     * read with locking reads (inside addPayment's transaction). $today ('Y-m-d', Berlin) is
     * needed only for the overdue status.
     *
     * @param array<string, mixed> $doc
     * @return array{status: string, gross_minor: int, paid_minor: int, credited_minor: int, balance_minor: int, due_date: ?string}
     */
    private function summary(array $doc, bool $lock, ?string $today): array
    {
        $s = ['status' => 'draft', 'gross_minor' => $doc['gross_minor'], 'paid_minor' => 0, 'credited_minor' => 0,
            'balance_minor' => 0, 'due_date' => $doc['due_date']];
        if ($doc['type'] !== DocType::INVOICE) {
            $s['status'] = $doc['status'] === 'draft' ? 'draft' : 'issued';
            return $s;
        }
        if ($doc['status'] === 'draft') {
            $s['balance_minor'] = $doc['gross_minor'];
            return $s;
        }
        foreach ($this->docs->payments((int) $doc['id'], $lock) as $p) {
            $s['paid_minor'] += $p['amount_minor'];
        }
        foreach ($this->docs->issuedCreditNotes((int) $doc['id'], $lock) as $cn) {
            $s['credited_minor'] += abs($cn['gross_minor']);
        }
        $s['balance_minor'] = max(0, $doc['gross_minor'] - $s['paid_minor'] - $s['credited_minor']);
        if ($s['credited_minor'] > 0 && $s['credited_minor'] >= $doc['gross_minor']) {
            $s['status'] = 'credited';
        } elseif ($s['balance_minor'] === 0) {
            $s['status'] = 'paid';
        } elseif ($today !== null && $doc['due_date'] !== null && $doc['due_date'] < $today) {
            $s['status'] = 'overdue'; // due today is not overdue; overdue wins over partial
        } else {
            $s['status'] = $s['paid_minor'] > 0 ? 'partial' : 'open';
        }
        return $s;
    }

    /** @return list<array<string, mixed>> the stored lines in input shape */
    private function lineInput(int $id): array
    {
        return array_map(
            fn (array $l): array => array_intersect_key($l, array_flip(['item_id', 'name', 'description', 'unit', 'qty_milli', 'unit_minor', 'discount_bp', 'vat_bp'])),
            $this->docs->lines($id)
        );
    }

    /** @param array<string, mixed> $l */
    private static function toLine(array $l): Line
    {
        return new Line((int) $l['qty_milli'], (int) $l['unit_minor'], (int) $l['discount_bp'], (int) $l['vat_bp']);
    }

    /** @return array{net_minor: int, vat_minor: int, gross_minor: int, vat_eur_minor: ?int} */
    private static function signedTotals(Totals $t, int $sign): array
    {
        return [
            'net_minor' => $sign * $t->netMinor,
            'vat_minor' => $sign * $t->vatMinor,
            'gross_minor' => $sign * $t->grossMinor,
            'vat_eur_minor' => $t->vatEurMinor === null ? null : $sign * $t->vatEurMinor,
        ];
    }

    /**
     * @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    private static function buyerSnapshot(array $customer): array
    {
        $b = [];
        foreach (self::BUYER_FIELDS as $f) {
            $b[$f] = $customer[$f] ?? null;
        }
        $b['is_company'] = (int) $b['is_company'];
        $b['lang'] = $customer['default_lang'] ?? null;
        return $b;
    }

    /**
     * @param array<string, mixed> $company
     * @return array<string, mixed>
     */
    private static function sellerSnapshot(array $company): array
    {
        $s = [];
        foreach (self::SELLER_FIELDS as $f) {
            $s[$f] = $company[$f] ?? null;
        }
        return $s;
    }

    /**
     * R29 (§14(4) Nr. 1, 2 UStG): an issued document must name both parties with their address and
     * carry a tax ID of the seller (Steuernummer or USt-IdNr.; under reverse charge the USt-IdNr.).
     * Checked on the snapshots before a number is drawn, so a refusal consumes no number.
     *
     * @param array<string, mixed> $seller
     * @param array<string, mixed> $buyer
     * Offers (R30) only need both names.
     *
     * @throws ValidationException keys seller | seller_vat | buyer
     */
    private static function assertMandatoryParties(array $seller, array $buyer, bool $reverseCharge, bool $offer = false): void
    {
        $blank = fn (mixed $v): bool => trim((string) $v) === '';
        $errors = [];
        if ($offer) {
            // R30: an offer is not a tax invoice; only both names are required.
            if ($blank($seller['name'] ?? null)) {
                $errors['seller'] = 'document.err_seller_incomplete';
            }
        } elseif ($blank($seller['name'] ?? null) || $blank($seller['address'] ?? null)
            || ($blank($seller['tax_number'] ?? null) && $blank($seller['vat_id'] ?? null))) {
            $errors['seller'] = 'document.err_seller_incomplete';
        } elseif ($reverseCharge && $blank($seller['vat_id'] ?? null)) {
            $errors['seller_vat'] = 'document.err_seller_vat_required';
        }
        if ($blank($buyer['name'] ?? null) || (!$offer && $blank($buyer['address'] ?? null))) {
            $errors['buyer'] = 'document.err_buyer_incomplete';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /** @param array<string, mixed> $detail ids and flags only, never personal data */
    private function audit(string $action, array $detail): void
    {
        $this->audit ??= new Audit($this->db, $this->clock);
        $this->audit->log($action, $detail, $this->ctx->id(), $this->ctx->userId());
    }

    private function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s', ($this->clock)());
    }

    private function berlin(\DateTimeImmutable $d): \DateTimeImmutable
    {
        return $d->setTimezone(new \DateTimeZone('Europe/Berlin'));
    }

    /** @param array<string, mixed> $v true when every string value is valid UTF-8 (JSON-encodable) */
    private static function validUtf8(array $v): bool
    {
        foreach ($v as $x) {
            if (is_string($x) && !mb_check_encoding($x, 'UTF-8')) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $v */
    private static function json(array $v): string
    {
        return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** int, or a canonical integer string; anything else (floats, '1.5', arrays) is null */
    private static function intOf(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/\A-?(?:0|[1-9][0-9]{0,17})\z/', $v) === 1) {
            return (int) $v;
        }
        return null;
    }

    /** 'Y-m-d' of a real calendar date, else null */
    private static function dateOf(mixed $v): ?string
    {
        if (!is_string($v) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $v, $m) !== 1) {
            return null;
        }
        return (int) $m[1] >= 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
    }

    /** comparison form for the fixed credit note fields (null and '' are the same) */
    private static function loose(mixed $v): string
    {
        return $v === null ? '' : (is_scalar($v) ? (string) $v : "\0");
    }
}
