<?php
declare(strict_types=1);

namespace App\Repo;

use App\Core\Db;
use App\Domain\Units;
use App\Domain\ValidationException;
use App\Money\AmountParser;
use App\Tenant\CompanyContext;

/**
 * Items and services of the context company, with a name (and optional description) per
 * language. Both tables carry company_id and are reached only through the scoped* helpers.
 * Items are never deleted in v1; is_active hides them from the "pick from catalogue" lists.
 *
 * $lang (de|pl|en) is the UI language: it picks the display name and the number format that
 * price input is read in (see AmountParser).
 */
final class ItemRepo extends BaseRepo
{
    private const ITEMS = 'items';
    private const NAMES = 'item_names';
    public const LANGS = ['de', 'pl', 'en'];
    public const TYPES = ['service', 'goods'];
    public const CURRENCIES = ['EUR', 'PLN', 'USD'];
    public const VAT_RATES = [1900, 700, 0];
    private const NAME_MAX = 190;
    private const DESCRIPTION_MAX = 2000;

    public function __construct(Db $db, CompanyContext $ctx, private string $lang = 'de')
    {
        parent::__construct($db, $ctx);
        if (!in_array($lang, self::LANGS, true)) {
            $this->lang = 'de';
        }
    }

    /**
     * The item row plus `names`: lang => ['name' => string, 'description' => ?string].
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $item = $this->scopedOne(self::ITEMS, $id);
        if ($item === null) {
            return null;
        }
        $item['names'] = $this->namesByLang($this->scopedAll(self::NAMES, 'item_id = ?', [$id]));
        return $item;
    }

    /**
     * Items ordered by display name; each has `names` and `display_name` (UI language).
     * $q searches the names in all languages. With $activeOnly inactive items are left out.
     *
     * Search design (same as CustomerRepo): user input only goes into `?` parameters; % _ and
     * backslash are stripped from the term. SQLite's LOWER()/LIKE only fold ASCII, so the name
     * is also compared with the term as typed, upper-cased and title-cased (computed in PHP
     * with mb_*). Odd mixed-case spellings like "łÓdź" may miss on SQLite; accepted.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $q = '', bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'is_active = 1' : '1=1';
        $params = [];
        [$nameWhere, $nameParams] = $this->searchWhere($q);
        if ($nameWhere !== null) {
            $matches = $this->scopedAll(self::NAMES, $nameWhere, $nameParams);
            $ids = array_values(array_unique(array_map(fn (array $r): int => (int) $r['item_id'], $matches)));
            if ($ids === []) {
                return [];
            }
            $where .= ' AND id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            $params = $ids;
        }
        $items = $this->scopedAll(self::ITEMS, $where, $params, 'id ASC');
        $names = [];
        foreach ($this->scopedAll(self::NAMES, '1=1', [], 'id ASC') as $row) {
            $names[(int) $row['item_id']][] = $row;
        }
        foreach ($items as &$item) {
            $item['names'] = $this->namesByLang($names[(int) $item['id']] ?? []);
            $item['display_name'] = $this->nameFor($item, $this->lang);
        }
        unset($item);
        usort($items, fn (array $a, array $b): int => [mb_strtolower($a['display_name'], 'UTF-8'), (int) $a['id']]
            <=> [mb_strtolower($b['display_name'], 'UTF-8'), (int) $b['id']]);
        return $items;
    }

    /**
     * Creates (or, with $id, updates) an item from form-like input; one transaction for the item
     * row and all its name rows. Keys: name_<lang>, description_<lang>, type, unit, price (typed,
     * read in the UI language), currency, vat_bp. On update, keys that are missing keep their
     * stored value; a present but empty name_<lang> removes that language. company_id, id,
     * is_active and timestamps in $data are ignored.
     *
     * @param array<string, mixed> $data
     * @throws ValidationException with field => translation key; nothing is written
     * @throws \InvalidArgumentException when $id does not belong to the company
     */
    public function save(array $data, ?int $id = null): int
    {
        $existing = null;
        if ($id !== null) {
            $existing = $this->find($id) ?? throw new \InvalidArgumentException('Unknown item.');
        }
        [$row, $names, $errors] = $this->validate($data, $existing);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $now = gmdate('Y-m-d H:i:s');
        return (int) $this->db->transaction(function () use ($row, $names, $existing, $now): int {
            if ($existing === null) {
                $itemId = $this->scopedInsert(self::ITEMS, $row + ['is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $itemId = (int) $existing['id'];
                $this->scopedUpdate(self::ITEMS, $itemId, $row + ['updated_at' => $now]);
            }
            $stored = [];
            foreach ($this->scopedAll(self::NAMES, 'item_id = ?', [$itemId]) as $r) {
                $stored[(string) $r['lang']] = $r;
            }
            foreach ($names as $lang => $n) {
                $old = $stored[$lang] ?? null;
                if ($n === null) {
                    if ($old !== null) {
                        $this->scopedDelete(self::NAMES, (int) $old['id']);
                    }
                } elseif ($old !== null) {
                    $this->scopedUpdate(self::NAMES, (int) $old['id'], $n);
                } else {
                    $this->scopedInsert(self::NAMES, $n + ['item_id' => $itemId, 'lang' => $lang]);
                }
            }
            return $itemId;
        });
    }

    /** Unknown ids (or another company's) change nothing. */
    public function setActive(int $id, bool $active): void
    {
        $this->scopedUpdate(self::ITEMS, $id, ['is_active' => $active ? 1 : 0, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    /**
     * Name in $lang, else German, else the first available name ('' when there is none).
     *
     * @param array<string, mixed> $item as returned by find()/list()
     */
    public function nameFor(array $item, string $lang): string
    {
        $names = is_array($item['names'] ?? null) ? $item['names'] : [];
        foreach ([$lang, 'de'] as $l) {
            $name = (string) ($names[$l]['name'] ?? '');
            if ($name !== '') {
                return $name;
            }
        }
        foreach ($names as $n) {
            if ((string) ($n['name'] ?? '') !== '') {
                return (string) $n['name'];
            }
        }
        return '';
    }

    /**
     * @param list<array<string, mixed>> $rows item_names rows
     * @return array<string, array{name: string, description: ?string}>
     */
    private function namesByLang(array $rows): array
    {
        $byLang = [];
        foreach ($rows as $r) {
            $byLang[(string) $r['lang']] = ['name' => (string) $r['name'], 'description' => $r['description'] === null ? null : (string) $r['description']];
        }
        $ordered = [];
        foreach (self::LANGS as $l) {
            if (isset($byLang[$l])) {
                $ordered[$l] = $byLang[$l];
            }
        }
        return $ordered;
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, mixed>|null $existing
     * @return array{0: array<string, mixed>, 1: array<string, array{name: string, description: ?string}|null>, 2: array<string, string>}
     */
    private function validate(array $in, ?array $existing): array
    {
        $errors = [];
        $str = fn (string $k): string => is_scalar($in[$k] ?? null) ? trim((string) $in[$k]) : '';
        $has = fn (string $k): bool => array_key_exists($k, $in);

        $names = [];
        $anyName = false;
        foreach (self::LANGS as $l) {
            $stored = $existing['names'][$l] ?? null;
            $name = $has("name_$l") ? $str("name_$l") : (string) ($stored['name'] ?? '');
            $desc = $has("description_$l")
                ? trim(str_replace(["\r\n", "\r"], "\n", $str("description_$l")))
                : (string) ($stored['description'] ?? '');
            if ($name === '') {
                $names[$l] = null;
                continue;
            }
            $anyName = true;
            if (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
                $errors["name_$l"] = 'item.err_name_length';
            }
            if (mb_strlen($desc, 'UTF-8') > self::DESCRIPTION_MAX) {
                $errors["description_$l"] = 'item.err_description';
            }
            $names[$l] = ['name' => $name, 'description' => $desc === '' ? null : $desc];
        }
        if (!$anyName) {
            $errors['names'] = 'item.err_names';
        }

        $type = $has('type') ? $str('type') : (string) ($existing['type'] ?? 'service');
        if (!in_array($type, self::TYPES, true)) {
            $errors['type'] = 'item.err_type';
        }
        $unit = $has('unit') ? $str('unit') : (string) ($existing['unit'] ?? 'hour');
        if (!Units::isValid($unit)) {
            $errors['unit'] = 'item.err_unit';
        }
        $currency = $has('currency') ? $str('currency') : (string) ($existing['currency'] ?? 'EUR');
        if (!in_array($currency, self::CURRENCIES, true)) {
            $errors['currency'] = 'item.err_currency';
        }

        $vat = $has('vat_bp') ? $in['vat_bp'] : ($existing['vat_bp'] ?? 1900);
        $vatBp = null;
        if (is_int($vat) || (is_string($vat) && preg_match('/\A[0-9]{1,5}\z/', trim($vat)) === 1)) {
            $vatBp = (int) trim((string) $vat);
        }
        if ($vatBp === null || !in_array($vatBp, self::VAT_RATES, true)) {
            $errors['vat_bp'] = 'item.err_vat';
        }

        $price = (int) ($existing['price_minor'] ?? 0);
        if ($has('price') || $existing === null) {
            try {
                $price = AmountParser::parse($str('price'), $this->lang);
            } catch (\InvalidArgumentException) {
                $errors['price'] = 'item.err_price';
            }
        }

        return [[
            'type' => $type, 'unit' => $unit, 'price_minor' => $price, 'currency' => $currency, 'vat_bp' => $vatBp ?? 0,
        ], $names, $errors];
    }

    /** @return array{0: ?string, 1: list<string>} a literal WHERE fragment for item_names and its parameters */
    private function searchWhere(string $q): array
    {
        $term = trim(str_replace(['%', '_', '\\'], '', $q));
        if ($term === '') {
            return [null, []];
        }
        $lower = mb_strtolower($term, 'UTF-8');
        $variants = array_values(array_unique([
            $term, $lower, mb_strtoupper($term, 'UTF-8'), mb_convert_case($term, MB_CASE_TITLE, 'UTF-8'),
        ]));
        $conds = ['LOWER(name) LIKE ?'];
        $params = ['%' . $lower . '%'];
        foreach ($variants as $v) {
            $conds[] = 'name LIKE ?';
            $params[] = '%' . $v . '%';
        }
        return [implode(' OR ', $conds), $params];
    }
}
