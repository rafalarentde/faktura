<?php
declare(strict_types=1);

namespace App\Http;

use App\Domain\Line;
use App\Domain\Percent;
use App\Domain\Totals;
use App\Domain\ValidationException;
use App\Money\AmountParser;
use App\Money\Formatter;
use App\Money\QuantityParser;

/**
 * The invoice editor's form state and its ONE parser, used by save, issue, create and the live
 * totals endpoint (POST /invoices/recalc), so typed values are read identically everywhere.
 *
 * - State: head fields and line rows exactly as typed (strings), for re-rendering after an error.
 * - fromPost() reads only whitelisted keys (HEAD_FIELDS, `lines[i][LINE_FIELDS]`); anything else
 *   in the body (company_id, id, type, status, number, totals, vat_*, reverse_charge, ...) is never
 *   looked at. Non-string values become ''.
 * - parse() turns the typed strings into DocumentService::createDraft/updateDraft `$data`: amounts
 *   via AmountParser, quantities via QuantityParser, discounts via Percent, all in the UI
 *   language. Errors use the service's keys (`lines.<i>.qty_milli`, `lines.<i>.unit_minor`, ...)
 *   with <i> the FORM row index. Completely blank rows are skipped; `rows` maps the service's
 *   line index back to the form row index (remapErrors()).
 * - totals() computes Totals for parsed lines (same validation keys).
 */
final class InvoiceForm
{
    public const HEAD_FIELDS = ['customer_id', 'lang', 'currency', 'eur_rate', 'issue_date', 'service_date', 'due_date',
        'valid_until', 'intro_text', 'outro_text', 'notes'];
    /** Head fields only offers have (valid_until) or lack (service and due date): see parse(). */
    private const OFFER_ONLY = ['valid_until'];
    private const NOT_FOR_OFFERS = ['service_date', 'due_date'];
    /** Head fields a credit note takes from its invoice: never sent for credit notes. */
    public const FIXED_FOR_CREDIT_NOTE = ['customer_id', 'lang', 'currency', 'eur_rate'];
    public const LINE_FIELDS = ['name', 'description', 'qty', 'unit', 'price', 'discount', 'vat_bp', 'item_id'];
    /** Rows accepted from a request (the service allows 200 lines; one extra blank row is fine). */
    public const MAX_ROWS = 201;
    private const SERVICE_FIELD = ['qty' => 'qty_milli', 'price' => 'unit_minor', 'discount' => 'discount_bp'];

    /**
     * @param array<string, string> $fields HEAD_FIELDS => typed value
     * @param list<array<string, string>> $lines rows of LINE_FIELDS => typed value
     */
    public function __construct(public array $fields, public array $lines)
    {
    }

    /** @param array<string, mixed> $post the request body */
    public static function fromPost(array $post): self
    {
        $fields = [];
        foreach (self::HEAD_FIELDS as $f) {
            $fields[$f] = is_string($post[$f] ?? null) ? $post[$f] : '';
        }
        $lines = [];
        $rows = is_array($post['lines'] ?? null) ? $post['lines'] : [];
        foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
            $line = [];
            foreach (self::LINE_FIELDS as $f) {
                $line[$f] = is_array($row) && is_string($row[$f] ?? null) ? $row[$f] : '';
            }
            $lines[] = $line;
        }
        return new self($fields, $lines);
    }

    /**
     * The stored draft as the form shows it (amounts and quantities in the UI language).
     *
     * @param array<string, mixed> $doc DocumentRepo::find() row
     */
    public static function fromDocument(array $doc, string $uiLang): self
    {
        $fmt = new Formatter();
        $fields = [
            'customer_id' => $doc['customer_id'] === null ? '' : (string) $doc['customer_id'],
            'lang' => (string) $doc['lang'], 'currency' => (string) $doc['currency'], 'eur_rate' => (string) ($doc['eur_rate'] ?? ''),
            'issue_date' => (string) ($doc['issue_date'] ?? ''), 'service_date' => (string) ($doc['service_date'] ?? ''),
            'due_date' => (string) ($doc['due_date'] ?? ''), 'valid_until' => (string) ($doc['valid_until'] ?? ''), 'intro_text' => (string) ($doc['intro_text'] ?? ''),
            'outro_text' => (string) ($doc['outro_text'] ?? ''), 'notes' => (string) ($doc['notes'] ?? ''),
        ];
        $lines = [];
        foreach ($doc['lines'] as $l) {
            $lines[] = [
                'name' => (string) $l['name'], 'description' => (string) ($l['description'] ?? ''),
                'qty' => $fmt->quantity((int) $l['qty_milli'], $uiLang), 'unit' => (string) $l['unit'],
                'price' => $fmt->decimalAmount((int) $l['unit_minor'], $uiLang),
                'discount' => (int) $l['discount_bp'] === 0 ? '' : Percent::format((int) $l['discount_bp'], $uiLang),
                'vat_bp' => (string) (int) $l['vat_bp'], 'item_id' => $l['item_id'] === null ? '' : (string) $l['item_id'],
            ];
        }
        return new self($fields, $lines === [] ? [self::blankLine()] : $lines);
    }

    /** @return array<string, string> */
    public static function blankLine(): array
    {
        return ['name' => '', 'description' => '', 'qty' => '1', 'unit' => 'hour', 'price' => '', 'discount' => '', 'vat_bp' => '1900', 'item_id' => ''];
    }

    /**
     * Parses the typed state. With $creditNote the fixed head fields are left out of `data`
     * (the service keeps the invoice's values). With $offer valid_until is handed over and the
     * service/due dates are not (offers have none); without it valid_until never reaches the
     * service, whatever the body contained.
     *
     * @return array{data: array<string, mixed>, errors: array<string, string>, rows: list<int>}
     */
    public function parse(string $uiLang, bool $creditNote = false, bool $offer = false): array
    {
        $data = [];
        $errors = [];
        foreach (self::HEAD_FIELDS as $f) {
            if ($creditNote && in_array($f, self::FIXED_FOR_CREDIT_NOTE, true)) {
                continue;
            }
            if (in_array($f, $offer ? self::NOT_FOR_OFFERS : self::OFFER_ONLY, true)) {
                continue;
            }
            $data[$f] = $this->fields[$f];
        }
        if (!$creditNote) {
            $data['eur_rate'] = $this->rate();
        }
        $data['lines'] = [];
        $rows = [];
        foreach ($this->lines as $i => $l) {
            if (self::isBlank($l)) {
                continue;
            }
            $p = "lines.$i.";
            $line = ['name' => $l['name'], 'description' => $l['description'], 'unit' => $l['unit'],
                'qty_milli' => null, 'unit_minor' => null, 'discount_bp' => 0, 'vat_bp' => $l['vat_bp'],
                'item_id' => $l['item_id'] === '' ? null : $l['item_id']];
            try {
                $line['qty_milli'] = QuantityParser::parse($l['qty'], $uiLang);
            } catch (\InvalidArgumentException) {
                $errors[$p . 'qty_milli'] = 'document.err_line_qty';
            }
            try {
                $line['unit_minor'] = AmountParser::parse($l['price'], $uiLang);
            } catch (\InvalidArgumentException) {
                $errors[$p . 'unit_minor'] = 'document.err_line_price';
            }
            if (trim($l['discount']) !== '') {
                try {
                    $line['discount_bp'] = Percent::parseBp($l['discount']);
                    if ($line['discount_bp'] > 10000) {
                        $errors[$p . 'discount_bp'] = 'document.err_line_discount';
                    }
                } catch (\InvalidArgumentException) {
                    $errors[$p . 'discount_bp'] = 'document.err_line_discount';
                }
            }
            if (!in_array($l['vat_bp'], array_map('strval', Line::VAT_RATES_BP), true)) {
                $errors[$p . 'vat_bp'] = 'document.err_line_vat';
            }
            $data['lines'][] = $line;
            $rows[] = $i;
        }
        if ($data['lines'] === []) {
            $errors['lines'] = 'document.err_lines';
        }
        return ['data' => $data, 'errors' => $errors, 'rows' => $rows];
    }

    /**
     * Service errors use the index of the line in `data`; the form shows them at the form row.
     *
     * @param array<string, string> $errors
     * @param list<int> $rows data index => form row index (from parse())
     * @return array<string, string>
     */
    public static function remapErrors(array $errors, array $rows): array
    {
        $out = [];
        foreach ($errors as $key => $msg) {
            if (preg_match('/\Alines\.(\d+)(\..+)?\z/', $key, $m) === 1 && isset($rows[(int) $m[1]])) {
                $key = 'lines.' . $rows[(int) $m[1]] . ($m[2] ?? '');
            }
            $out[$key] = $msg;
        }
        return $out;
    }

    /**
     * Totals of parsed lines (no persistence). Validation keys as the service uses them.
     *
     * @param list<array<string, mixed>> $lines parse()['data']['lines'] without errors
     * @throws ValidationException
     */
    public static function totals(array $lines, bool $reverseCharge, string $currency, ?string $rate): Totals
    {
        if (!in_array($currency, ['EUR', 'PLN', 'USD'], true)) {
            throw new ValidationException(['currency' => 'document.err_currency']);
        }
        if ($rate !== null) {
            try {
                Totals::parseRate($rate);
            } catch (\InvalidArgumentException) {
                throw new ValidationException(['eur_rate' => 'document.err_eur_rate']);
            }
            if ($currency === 'EUR') {
                $rate = null;
            }
        } elseif ($currency !== 'EUR' && !$reverseCharge) {
            throw new ValidationException(['eur_rate' => 'document.err_eur_rate_required']);
        }
        try {
            return Totals::compute(array_map(
                fn (array $l): Line => new Line((int) $l['qty_milli'], (int) $l['unit_minor'], (int) $l['discount_bp'], (int) $l['vat_bp']),
                $lines
            ), $reverseCharge, $currency, $rate);
        } catch (\OverflowException) {
            throw new ValidationException(['lines' => 'document.err_total_overflow']);
        } catch (\InvalidArgumentException) {
            throw new ValidationException(['lines' => 'document.err_lines']);
        }
    }

    /** Net of one typed row, or null when it cannot be computed yet (blank or invalid input). */
    public static function lineNet(array $row, string $uiLang): ?int
    {
        try {
            $discount = trim($row['discount']) === '' ? 0 : Percent::parseBp($row['discount']);
            return Totals::lineNet(new Line(
                QuantityParser::parse($row['qty'], $uiLang),
                AmountParser::parse($row['price'], $uiLang),
                $discount,
                (int) $row['vat_bp'],
            ));
        } catch (\InvalidArgumentException | \OverflowException) {
            return null;
        }
    }

    /**
     * The exchange rate as the service wants it ("4.2650"): '' = none; a single comma is read as
     * the decimal point ("4,2650"); EUR documents never carry a rate.
     */
    public function rate(): ?string
    {
        if ($this->fields['currency'] === 'EUR') {
            return null;
        }
        $r = trim($this->fields['eur_rate']);
        if ($r === '') {
            return null;
        }
        return str_contains($r, '.') ? $r : str_replace(',', '.', $r);
    }

    /** @param array<string, string> $row */
    private static function isBlank(array $row): bool
    {
        foreach (['name', 'description', 'price', 'discount'] as $f) {
            if (trim($row[$f]) !== '') {
                return false;
            }
        }
        return true;
    }
}
