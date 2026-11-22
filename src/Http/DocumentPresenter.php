<?php
declare(strict_types=1);

namespace App\Http;

use App\Domain\Countries;
use App\Domain\DocType;
use App\Domain\DocumentService;
use App\Domain\Line;
use App\Domain\Percent;
use App\Domain\Totals;
use App\I18n\Translator;
use App\Money\Formatter;
use App\Money\Money;
use App\Repo\DocumentRepo;

/**
 * The ONE view model of a document (R28), shared by the on-screen paper preview
 * (templates/invoices/_paper.twig), the PDF (templates/pdf/*.twig) and the mail body (Task 18).
 *
 * - Everything is in the DOCUMENT language (documents.lang): labels, number/date formats, unit
 *   names, the reverse-charge note, country names.
 * - Values are plain strings; templates escape them. Multi-line texts (addresses, intro/outro,
 *   footer) come as lists of trimmed, non-empty lines, never as raw HTML.
 * - Issued documents (issued()) are built ONLY from the stored snapshots (seller/buyer), the stored
 *   lines and the stored totals; per-rate VAT comes from the stored lines (the same Totals::compute
 *   that wrote the stored sums at issue). Live company/customer rows are never read here.
 * - VAT rows name their net base ("USt 19 % auf 960,00 €"); none under reverse charge.
 * - Offers: title "Angebot", "valid until" instead of the due date, no payment line and no bank
 *   details (blanked here, so the screen and the PDF agree); otherwise the same lines and totals.
 * - Credit notes: lines stay positive, sums carry a leading minus; `related` names the original
 *   invoice (looked up with the company-scoped repo; null when it cannot be found).
 */
final class DocumentPresenter
{
    public function __construct(private Translator $translator, private DocumentRepo $docs)
    {
    }

    /**
     * View model of an ISSUED document from its snapshots, stored lines and stored totals.
     *
     * @param array<string, mixed> $doc DocumentRepo::find() row of an issued document
     * @return array<string, mixed>
     * @throws \LogicException for drafts (they have no snapshots)
     */
    public function issued(array $doc): array
    {
        if (($doc['status'] ?? null) !== 'issued') {
            throw new \LogicException('Only issued documents have an issued view model.');
        }
        if ($doc['type'] === DocType::DUNNING) {
            $invoice = $doc['related_document_id'] === null ? null : $this->docs->row((int) $doc['related_document_id']);
            return $this->dunning($doc, (array) ($doc['seller'] ?? []), (array) ($doc['buyer'] ?? []), $invoice);
        }
        return $this->present($doc, (array) ($doc['seller'] ?? []), (array) ($doc['buyer'] ?? []), self::totalsOf($doc));
    }

    /**
     * View model of any document state: an issued document, a draft (with the live company and
     * customer rows) or the editor's create-mode pseudo row. For issued documents the net, gross
     * and VAT-in-EUR sums are the STORED (signed) totals.
     *
     * @param array<string, mixed> $doc document row with lines (or the create-mode pseudo row)
     * @param array<string, mixed> $seller companies row or seller snapshot
     * @param array<string, mixed> $buyer customers row or buyer snapshot
     * @return array<string, mixed>
     */
    public function present(array $doc, array $seller, array $buyer, Totals $totals): array
    {
        $lang = in_array($doc['lang'] ?? null, DocumentService::LANGS, true) ? (string) $doc['lang'] : 'de';
        $tr = fn (string $key, array $vars = []): string => $this->translator->t($key, $lang, $vars);
        $fmt = new Formatter();
        $currency = (string) $doc['currency'];
        $rc = (int) $doc['reverse_charge'] === 1;
        $creditNote = $doc['type'] === DocType::CREDIT_NOTE;
        $offer = $doc['type'] === DocType::OFFER;
        $sign = $creditNote ? -1 : 1;
        $money = fn (int $minor): string => $fmt->money(Money::of($minor, $currency), $lang);
        $date = fn (mixed $d): ?string => $d === null || $d === '' ? null : $fmt->date(new \DateTimeImmutable((string) $d), $lang);

        $rows = [];
        $hasDiscount = false;
        foreach ($doc['lines'] as $i => $l) {
            $net = $l['net_minor'] ?? Totals::lineNet(self::line($l));
            $hasDiscount = $hasDiscount || (int) $l['discount_bp'] !== 0;
            $rows[] = [
                'pos' => $i + 1, 'name' => (string) $l['name'], 'description' => self::text($l['description'] ?? ''),
                'qty' => $fmt->quantity((int) $l['qty_milli'], $lang), 'unit' => $tr('unit.' . $l['unit']),
                'price' => $money((int) $l['unit_minor']),
                'discount' => (int) $l['discount_bp'] === 0 ? '' : Percent::format((int) $l['discount_bp'], $lang) . ' %',
                'vat' => ($rc ? '0' : Percent::format((int) $l['vat_bp'], $lang)) . ' %',
                'net' => $money((int) $net),
            ];
        }
        // §14(4) Nr. 7/8 UStG: VAT per rate together with the net amount it is computed on. Under
        // reverse charge there is no VAT row at all (Totals::vat is empty); the note says why.
        $vat = [];
        foreach ($totals->vat as $bp => $amount) {
            $vat[] = [
                'label' => $tr('invoice.vat_on', [
                    'label' => $tr('invoice.vat') . ' ' . Percent::format($bp, $lang) . ' %',
                    'net' => $money($sign * (int) ($totals->nets[$bp] ?? 0)),
                ]),
                'amount' => $money($sign * $amount),
            ];
        }

        // Sums: stored (already signed) for issued documents, computed otherwise.
        $issued = ($doc['status'] ?? null) === 'issued';
        $netMinor = $issued ? (int) $doc['net_minor'] : $sign * $totals->netMinor;
        $grossMinor = $issued ? (int) $doc['gross_minor'] : $sign * $totals->grossMinor;
        $vatEurMinor = $issued
            ? ($doc['vat_eur_minor'] === null ? null : (int) $doc['vat_eur_minor'])
            : ($totals->vatEurMinor === null ? null : $sign * $totals->vatEurMinor);

        $related = null;
        if ($creditNote && ($doc['related_document_id'] ?? null) !== null) {
            $inv = $this->docs->row((int) $doc['related_document_id']);
            if ($inv !== null && $inv['number'] !== null) {
                $related = $tr('invoice.corrects', ['number' => (string) $inv['number'], 'date' => (string) $date($inv['issue_date'])]);
            }
        }
        $title = $tr($creditNote ? 'invoice.type_credit_note' : ($offer ? 'offer.doc_title' : 'invoice.title'));
        $number = $doc['number'] ?? $tr('invoice.draft_number');
        // Credit notes and offers have no due date (offers: "valid until" instead, no payment terms).
        $dueDate = $creditNote || $offer ? null : $date($doc['due_date']);
        $rate = $doc['eur_rate'] ?? null;
        $country = strtoupper((string) ($buyer['country'] ?? ''));

        return [
            'lang' => $lang,
            'kind' => $creditNote ? DocType::CREDIT_NOTE : ($offer ? DocType::OFFER : DocType::INVOICE),
            'title' => $title,
            'number' => $number,
            'doc_title' => $title . ' ' . $number,
            'currency' => $currency,
            'labels' => [
                'bill_to' => $tr($offer ? 'offer.bill_to' : 'invoice.bill_to'), 'position' => $tr('invoice.position'), 'description' => $tr('invoice.description'),
                'quantity' => $tr('invoice.quantity'), 'unit_price' => $tr('invoice.unit_price'), 'discount' => $tr('invoice.discount'),
                'vat' => $tr('invoice.vat'), 'amount' => $tr('invoice.amount'), 'net' => $tr('invoice.net'), 'total' => $tr('invoice.total'),
                'issue_date' => $tr($offer ? 'offer.date' : 'invoice.date'), 'valid_until' => $tr('offer.valid_until'), 'service_date' => $tr('invoice.service_date'), 'service_same' => $tr('invoice.service_same_as_issue'), 'due_date' => $tr('invoice.due_date'),
                'vat_id' => $tr('invoice.vat_id'), 'tax_number' => $tr('invoice.tax_number'), 'vat_in_eur' => $tr('invoice.vat_in_eur'),
                'exchange_rate' => $tr('invoice.exchange_rate'), 'number' => $tr('pdf.number'), 'bank_details' => $tr('pdf.bank_details'),
                'page' => $tr('pdf.page'),
            ],
            'issue_date' => $date($doc['issue_date']),
            'service_date' => $date($doc['service_date']),
            // Supply date equal to the invoice date: the document says so in words (UStG § 14 Abs. 4 Nr. 6).
            'service_same' => !$offer && ($doc['service_date'] ?? null) !== null && ($doc['service_date'] ?? null) === ($doc['issue_date'] ?? null),
            'due_date' => $dueDate,
            'valid_until' => $offer ? $date($doc['valid_until'] ?? null) : null,
            'payment' => $dueDate === null ? null : $tr('pdf.payment_due', ['date' => $dueDate]),
            'seller' => [
                'name' => (string) ($seller['name'] ?? ''), 'address' => self::lines($seller['address'] ?? ''),
                'vat_id' => (string) ($seller['vat_id'] ?? ''), 'tax_number' => (string) ($seller['tax_number'] ?? ''),
                // An offer is no payment request: it carries no bank details.
                'iban' => $offer ? '' : (string) ($seller['iban'] ?? ''), 'bic' => $offer ? '' : (string) ($seller['bic'] ?? ''),
                'bank' => $offer ? '' : (string) ($seller['bank'] ?? ''), 'footer' => self::lines($seller['footer_text'] ?? ''),
            ],
            'buyer' => [
                'name' => (string) ($buyer['name'] ?? ''), 'address' => self::lines($buyer['address'] ?? ''),
                'country' => Countries::isValid($country) ? (string) \Locale::getDisplayRegion('-' . $country, $lang) : '',
                'vat_id' => (string) ($buyer['vat_id'] ?? ''),
            ],
            'intro' => self::lines($doc['intro_text'] ?? ''),
            'outro' => self::lines($doc['outro_text'] ?? ''),
            'rows' => $rows,
            'has_discount' => $hasDiscount,
            'net' => $money($netMinor),
            'vat' => $vat,
            'gross' => $money($grossMinor),
            'vat_eur' => $currency !== 'EUR' && !$rc && $vatEurMinor !== null ? $fmt->money(Money::of($vatEurMinor, 'EUR'), $lang) : null,
            'rate' => $currency !== 'EUR' && $rate !== null && $rate !== ''
                ? '1 EUR = ' . ($lang === 'en' ? (string) $rate : str_replace('.', ',', (string) $rate)) . ' ' . $currency : null,
            'note' => ($doc['vat_note_key'] ?? null) !== null && $doc['vat_note_key'] !== '' ? $tr((string) $doc['vat_note_key']) : null,
            'related' => $related,
        ];
    }

    /**
     * View model of a dunning letter (Task 21) in the DOCUMENT language: an issued row (from its
     * snapshots and stored dunning_* columns, via issued()) or the preview's pseudo row (the same
     * keys from DocumentService::dunningQuote, number null, live seller row). The letter is: title
     * per level, the reference line to the invoice, a formal text per level, the breakdown (only
     * the charged extras), the total claim, the pay-by date and the bank details.
     *
     * @param array<string, mixed> $doc keys type, lang, currency, number (?string), issue_date, dunning_level,
     *        dunning_outstanding_minor, dunning_interest_minor/_days/_rate_bp, dunning_flat_fee_minor,
     *        dunning_fee_minor, dunning_claim_minor, dunning_pay_by
     * @param array<string, mixed> $seller seller snapshot (or the live companies row in the preview)
     * @param array<string, mixed> $buyer buyer snapshot (copied from the invoice)
     * @param array<string, mixed>|null $invoice the dunned invoice row (number, issue_date, due_date)
     * @return array<string, mixed>
     */
    public function dunning(array $doc, array $seller, array $buyer, ?array $invoice): array
    {
        $lang = in_array($doc['lang'] ?? null, DocumentService::LANGS, true) ? (string) $doc['lang'] : 'de';
        $tr = fn (string $key, array $vars = []): string => $this->translator->t($key, $lang, $vars);
        $fmt = new Formatter();
        $date = fn (mixed $d): string => $d === null || $d === '' ? '' : $fmt->date(new \DateTimeImmutable((string) $d), $lang);
        $level = max(0, min(3, (int) ($doc['dunning_level'] ?? 0)));
        $title = $tr('pdf.dunning_title_' . $level);
        $number = $doc['number'] ?? $tr('invoice.draft_number');
        $payBy = $date($doc['dunning_pay_by'] ?? null);
        $breakdown = $this->dunningRows($doc, $lang);
        $country = strtoupper((string) ($buyer['country'] ?? ''));
        $reference = $invoice === null || ($invoice['number'] ?? null) === null ? null : $tr('pdf.dunning_reference', [
            'number' => (string) $invoice['number'], 'date' => $date($invoice['issue_date'] ?? null), 'due' => $date($invoice['due_date'] ?? null),
        ]);
        return [
            'lang' => $lang,
            'kind' => DocType::DUNNING,
            'level' => $level,
            'title' => $title,
            'number' => $number,
            'doc_title' => $title . ' ' . $number,
            'currency' => (string) $doc['currency'],
            'labels' => [
                'bill_to' => $tr('pdf.dunning_to'), 'number' => $tr('pdf.number'), 'issue_date' => $tr('pdf.dunning_date'),
                'pay_by' => $tr('pdf.dunning_pay_by_label'), 'vat_id' => $tr('invoice.vat_id'), 'tax_number' => $tr('invoice.tax_number'),
                'bank_details' => $tr('pdf.bank_details'), 'page' => $tr('pdf.page'), 'amount' => $tr('invoice.amount'),
                'position' => $tr('invoice.description'),
            ],
            'issue_date' => $date($doc['issue_date'] ?? null),
            'pay_by' => $payBy,
            'due_date' => null,
            'valid_until' => null,
            'reference' => $reference,
            'related' => $reference,
            'invoice_number' => $invoice === null ? '' : (string) ($invoice['number'] ?? ''),
            'invoice_date' => $invoice === null ? '' : $date($invoice['issue_date'] ?? null),
            'greeting' => $tr('pdf.dunning_greeting'),
            'text' => [$tr('pdf.dunning_text_' . $level, ['date' => $payBy]), $tr('pdf.dunning_already_paid')],
            'rows' => $breakdown['rows'],
            'total_label' => $breakdown['total_label'],
            'gross' => $breakdown['total'],
            'payment' => $tr('pdf.dunning_pay_by', ['date' => $payBy]),
            'purpose' => $invoice === null || ($invoice['number'] ?? null) === null ? null : $tr('pdf.dunning_purpose', ['number' => (string) $invoice['number']]),
            'closing' => $tr('pdf.dunning_closing'),
            'seller' => [
                'name' => (string) ($seller['name'] ?? ''), 'address' => self::lines($seller['address'] ?? ''),
                'vat_id' => (string) ($seller['vat_id'] ?? ''), 'tax_number' => (string) ($seller['tax_number'] ?? ''),
                'iban' => (string) ($seller['iban'] ?? ''), 'bic' => (string) ($seller['bic'] ?? ''),
                'bank' => (string) ($seller['bank'] ?? ''), 'footer' => self::lines($seller['footer_text'] ?? ''),
            ],
            'buyer' => [
                'name' => (string) ($buyer['name'] ?? ''), 'address' => self::lines($buyer['address'] ?? ''),
                'country' => Countries::isValid($country) ? (string) \Locale::getDisplayRegion('-' . $country, $lang) : '',
                'vat_id' => (string) ($buyer['vat_id'] ?? ''),
            ],
        ];
    }

    /**
     * The breakdown of a dunning letter in $lang (document language for the letter, UI language on
     * the pages): the outstanding amount, then ONLY the charged extras (a NULL column is "not
     * charged"), and the total claim. Amounts come from the stored/quoted columns, never recomputed.
     *
     * @param array<string, mixed> $doc see dunning()
     * @return array{rows: list<array{key: string, label: string, amount: string}>, total_label: string, total: string}
     */
    public function dunningRows(array $doc, string $lang): array
    {
        $tr = fn (string $key, array $vars = []): string => $this->translator->t($key, $lang, $vars);
        $fmt = new Formatter();
        $currency = (string) $doc['currency'];
        $money = fn (int $minor): string => $fmt->money(Money::of($minor, $currency), $lang);
        $rows = [['key' => 'outstanding', 'label' => $tr('pdf.dunning_outstanding'), 'amount' => $money((int) $doc['dunning_outstanding_minor'])]];
        // Interest stored as 0 (the annual rate was not positive) is "nothing charged": no "0,00" row.
        if ((int) ($doc['dunning_interest_minor'] ?? 0) > 0) {
            $rows[] = ['key' => 'interest', 'label' => $tr('pdf.dunning_interest', [
                'rate' => Percent::format((int) $doc['dunning_interest_rate_bp'], $lang), 'days' => (string) (int) $doc['dunning_interest_days'],
            ]), 'amount' => $money((int) $doc['dunning_interest_minor'])];
        }
        if (($doc['dunning_flat_fee_minor'] ?? null) !== null) {
            $rows[] = ['key' => 'flat_fee', 'label' => $tr('pdf.dunning_flat_fee'), 'amount' => $money((int) $doc['dunning_flat_fee_minor'])];
        }
        if (($doc['dunning_fee_minor'] ?? null) !== null) {
            $rows[] = ['key' => 'fee', 'label' => $tr('pdf.dunning_fee'), 'amount' => $money((int) $doc['dunning_fee_minor'])];
        }
        return ['rows' => $rows, 'total_label' => $tr('pdf.dunning_total'), 'total' => $money((int) $doc['dunning_claim_minor'])];
    }

    /**
     * Totals block of the editor/recalc (UI language $lang), signed for credit notes.
     *
     * @return array{net: string, vat: list<array{label: string, amount: string}>, gross: string, vat_eur: ?string}
     */
    public function totalsPanel(Totals $t, int $sign, string $currency, bool $rc, string $lang): array
    {
        $fmt = new Formatter();
        $money = fn (int $minor, string $cur = ''): string => $fmt->money(Money::of($minor, $cur === '' ? $currency : $cur), $lang);
        $vatLabel = $this->translator->t('invoice.vat', $lang);
        $vat = [];
        foreach ($t->vat as $bp => $amount) {
            $vat[] = ['label' => $vatLabel . ' ' . Percent::format($bp, $lang) . ' %', 'amount' => $money($sign * $amount)];
        }
        if ($rc) {
            $vat[] = ['label' => $vatLabel . ' 0 % · ' . $this->translator->t('invoice.reverse_charge', $lang), 'amount' => $money(0)];
        }
        return [
            'net' => $money($sign * $t->netMinor),
            'vat' => $vat,
            'gross' => $money($sign * $t->grossMinor),
            'vat_eur' => $currency !== 'EUR' && !$rc && $t->vatEurMinor !== null ? $money($sign * $t->vatEurMinor, 'EUR') : null,
        ];
    }

    /**
     * Unsigned totals of a document from its stored lines (same as DocumentService::totalsFor,
     * which wrote the stored sums at issue).
     *
     * @param array<string, mixed> $doc
     */
    public static function totalsOf(array $doc): Totals
    {
        return Totals::compute(
            array_map(self::line(...), $doc['lines']),
            (int) $doc['reverse_charge'] === 1,
            (string) $doc['currency'],
            $doc['eur_rate'] === null ? null : (string) $doc['eur_rate'],
        );
    }

    /** @param array<string, mixed> $l */
    private static function line(array $l): Line
    {
        return new Line((int) $l['qty_milli'], (int) $l['unit_minor'], (int) $l['discount_bp'], (int) $l['vat_bp']);
    }

    /** Text with normalised line breaks, trimmed. */
    private static function text(mixed $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
    }

    /** @return list<string> trimmed, non-empty lines */
    private static function lines(mixed $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", self::text($text))), fn (string $l): bool => $l !== ''));
    }
}
