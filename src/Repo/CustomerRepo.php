<?php
declare(strict_types=1);

namespace App\Repo;

use App\Domain\Countries;
use App\Domain\ValidationException;
use App\Domain\VatId;
use App\Vies\ViesStatus;

/**
 * Customers of the context company (all access through the scoped* helpers).
 * Customers are never deleted in v1; documents keep their own snapshots.
 */
final class CustomerRepo extends BaseRepo
{
    private const TABLE = 'customers';
    public const LANGS = ['de', 'pl', 'en'];
    public const CURRENCIES = ['EUR', 'PLN', 'USD'];

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->scopedOne(self::TABLE, $id);
    }

    /**
     * Customers by name. $q searches name, VAT id and e-mail (substring, case-insensitive).
     *
     * Search design: user input only ever goes into `?` parameters. % and _ (and backslash) are
     * stripped from the term instead of escaped, because BaseRepo::checkWhere forbids
     * backslashes and therefore an ESCAPE clause. SQLite's LOWER()/LIKE only fold ASCII, so
     * "łódź" would not match "Łódź" there; to cover that without a collation dependency the
     * name is also compared (LIKE, on MySQL case-insensitive anyway) with the term as typed, in
     * upper case and in title case, all computed in PHP with mb_*. Mixed-case spellings that
     * are none of these (e.g. "łÓdź") may not match on SQLite; that is accepted.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $q = '', ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = $this->searchWhere($q);
        return $this->scopedAll(self::TABLE, $where, $params, 'name ASC, id ASC', $limit, $offset);
    }

    public function count(string $q = ''): int
    {
        [$where, $params] = $this->searchWhere($q);
        return $this->scopedCount(self::TABLE, $where, $params);
    }

    /**
     * Creates (or, with $id, updates) a customer from form-like input. Fields missing from $data
     * keep their stored value on update; on create they get defaults (country DE, company,
     * language and payment terms of the company). company_id in $data is ignored.
     * Changing the country or the VAT id resets the VIES result.
     *
     * @param array<string, mixed> $data
     * @throws ValidationException with field => translation key; nothing is written
     * @throws \InvalidArgumentException when $id does not belong to the company
     */
    public function save(array $data, ?int $id = null): int
    {
        $existing = null;
        if ($id !== null) {
            $existing = $this->find($id) ?? throw new \InvalidArgumentException('Unknown customer.');
        }
        $in = $data + ($existing ?? $this->defaults());
        [$row, $errors] = $this->validate($in);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($existing === null) {
            $row += ['vies_status' => null, 'vies_checked_at' => null, 'created_at' => $now, 'updated_at' => $now];
            return $this->scopedInsert(self::TABLE, $row);
        }
        if ($row['vat_id'] !== $existing['vat_id'] || $row['country'] !== $existing['country']) {
            $row += ['vies_status' => null, 'vies_checked_at' => null];
        }
        $this->scopedUpdate(self::TABLE, (int) $id, $row + ['updated_at' => $now]);
        return (int) $id;
    }

    /**
     * Stores the VIES result; $at is saved in UTC. The row is matched on the VAT id AND country that were
     * actually checked: when the customer was edited while the (slow) VIES call was in flight, the old answer
     * is NOT attached to the new number (reverse-charge safety). Unknown ids (or another company's) change nothing.
     *
     * @return bool whether the result was stored
     */
    public function recordVies(int $id, ViesStatus $status, \DateTimeImmutable $at, string $country, string $vatId): bool
    {
        return $this->db->exec(
            'UPDATE customers SET vies_status = ?, vies_checked_at = ? WHERE company_id = ? AND id = ? AND vat_id = ? AND country = ?',
            [$status->value, $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $this->ctx->id(), $id, $vatId, $country]
        ) === 1;
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        $company = $this->ctx->company();
        $lang = (string) ($company['default_lang'] ?? 'de');
        $currency = (string) ($company['default_currency'] ?? 'EUR');
        return [
            'name' => '', 'address' => '', 'country' => 'DE', 'is_company' => 1, 'vat_id' => '', 'email' => '',
            'default_lang' => in_array($lang, self::LANGS, true) ? $lang : 'de',
            'default_currency' => in_array($currency, self::CURRENCIES, true) ? $currency : 'EUR',
            'payment_days' => (int) ($company['default_payment_days'] ?? 14),
            'notes' => '',
        ];
    }

    /**
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validate(array $in): array
    {
        $errors = [];
        $str = fn (string $k): string => is_scalar($in[$k] ?? null) ? trim((string) $in[$k]) : '';
        $multiline = fn (string $k): string => trim(str_replace(["\r\n", "\r"], "\n", $str($k)));

        $name = $str('name');
        if ($name === '' || mb_strlen($name, 'UTF-8') > 190) {
            $errors['name'] = 'customer.err_name';
        }
        $address = $multiline('address');
        $notes = $multiline('notes');

        $country = $str('country');
        if (!Countries::isValid($country)) {
            $errors['country'] = 'customer.err_country';
        }

        $isCompany = filter_var($in['is_company'] ?? 1, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        $vatId = null;
        if ($str('vat_id') !== '' && !isset($errors['country'])) {
            $vatId = VatId::normalize($country, $str('vat_id'));
            if ($vatId !== null && preg_match('/\A[A-Z]{2}[A-Z0-9]{2,30}\z/', $vatId) !== 1) {
                $errors['vat_id'] = 'customer.err_vat_id';
            }
        }

        $email = $str('email');
        if ($email !== '' && (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'] = 'customer.err_email';
        }

        $lang = $str('default_lang');
        if (!in_array($lang, self::LANGS, true)) {
            $errors['default_lang'] = 'customer.err_lang';
        }
        $currency = $str('default_currency');
        if (!in_array($currency, self::CURRENCIES, true)) {
            $errors['default_currency'] = 'customer.err_currency';
        }
        $days = $str('payment_days');
        if (preg_match('/\A\d{1,3}\z/', $days) !== 1 || (int) $days > 365) {
            $errors['payment_days'] = 'customer.err_payment_days';
        }

        return [[
            'name' => $name,
            'address' => $address === '' ? null : $address,
            'country' => $country,
            'is_company' => $isCompany,
            'vat_id' => $vatId,
            'email' => $email === '' ? null : $email,
            'default_lang' => $lang,
            'default_currency' => $currency,
            'payment_days' => (int) $days,
            'notes' => $notes === '' ? null : $notes,
        ], $errors];
    }

    /** @return array{0: string, 1: list<string>} a literal WHERE fragment and its parameters */
    private function searchWhere(string $q): array
    {
        $term = trim(str_replace(['%', '_', '\\'], '', $q));
        if ($term === '') {
            return ['1=1', []];
        }
        $lower = mb_strtolower($term, 'UTF-8');
        $nameVariants = array_values(array_unique([
            $term, $lower, mb_strtoupper($term, 'UTF-8'), mb_convert_case($term, MB_CASE_TITLE, 'UTF-8'),
        ]));
        $conds = ['LOWER(name) LIKE ?', 'LOWER(vat_id) LIKE ?', 'LOWER(email) LIKE ?'];
        $params = ['%' . $lower . '%', '%' . $lower . '%', '%' . $lower . '%'];
        foreach ($nameVariants as $v) {
            $conds[] = 'name LIKE ?';
            $params[] = '%' . $v . '%';
        }
        return [implode(' OR ', $conds), $params];
    }
}
