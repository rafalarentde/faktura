<?php
declare(strict_types=1);

namespace Tests\Repo;

use App\Core\Db;
use App\Domain\Units;
use App\Domain\ValidationException;
use App\Repo\ItemRepo;
use App\Tenant\CompanyContext;
use PHPUnit\Framework\TestCase;

final class ItemRepoTest extends TestCase
{
    private Db $db;
    private int $companyA;
    private int $companyB;
    private ItemRepo $repo;
    private ItemRepo $repoB;

    protected function setUp(): void
    {
        $this->db = \TestDb::fresh();
        $this->companyA = $this->company('A GmbH');
        $this->companyB = $this->company('B GmbH');
        $this->repo = $this->repoFor($this->companyA, 'a@example.pl', 'de');
        $this->repoB = $this->repoFor($this->companyB, 'b@example.pl', 'de');
    }

    private function company(string $name): int
    {
        $this->db->exec('INSERT INTO companies (name) VALUES (?)', [$name]);
        return $this->db->lastId();
    }

    private function repoFor(int $companyId, string $email, string $lang): ItemRepo
    {
        $this->db->exec('INSERT INTO users (email, password_hash) VALUES (?, ?)', [$email, 'x']);
        $uid = $this->db->lastId();
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$companyId, $uid, 'owner']);
        return new ItemRepo($this->db, CompanyContext::forUser($this->db, $uid, $companyId), $lang);
    }

    private function inLang(string $lang): ItemRepo
    {
        return new ItemRepo($this->db, CompanyContext::forUser($this->db, (int) $this->db->one('SELECT user_id u FROM company_users WHERE company_id = ?', [$this->companyA])['u'], $this->companyA), $lang);
    }

    /** @return array<string, mixed> */
    private function valid(array $over = []): array
    {
        return $over + ['name_de' => 'Beratung', 'name_pl' => 'Doradztwo', 'name_en' => 'Consulting', 'type' => 'service',
            'unit' => 'hour', 'price' => '450,00', 'currency' => 'EUR', 'vat_bp' => '1900'];
    }

    /** @return array<string, string> */
    private function errors(array $data, ?int $id = null, ?ItemRepo $repo = null): array
    {
        try {
            ($repo ?? $this->repo)->save($data, $id);
        } catch (ValidationException $e) {
            return $e->errors;
        }
        $this->fail('Expected ValidationException');
    }

    private function rows(string $table): int
    {
        return (int) $this->db->one("SELECT COUNT(*) c FROM $table")['c'];
    }

    public function test_save_and_find_with_names(): void
    {
        $id = $this->repo->save($this->valid(['description_de' => '  Stundensatz ']));
        $item = $this->repo->find($id);
        $this->assertSame('service', $item['type']);
        $this->assertSame('hour', $item['unit']);
        $this->assertSame('EUR', $item['currency']);
        $this->assertSame(1900, (int) $item['vat_bp']);
        $this->assertSame(1, (int) $item['is_active']);
        $this->assertSame(['name' => 'Beratung', 'description' => 'Stundensatz'], $item['names']['de']);
        $this->assertSame(['name' => 'Doradztwo', 'description' => null], $item['names']['pl']);
        $this->assertSame('Consulting', $item['names']['en']['name']);
        $this->assertSame($this->companyA, (int) $item['company_id']);
    }

    public function test_prices_stored_as_integer_minor(): void
    {
        $id = $this->repo->save($this->valid(['price' => '1.234,56']));
        $this->assertSame(123456, (int) $this->db->one('SELECT price_minor FROM items WHERE id = ?', [$id])['price_minor']);
        $en = $this->inLang('en');
        $id2 = $en->save($this->valid(['price' => '450.50']));
        $this->assertSame(45050, (int) $this->db->one('SELECT price_minor FROM items WHERE id = ?', [$id2])['price_minor']);
        $this->assertSame(45050, $en->find($id2)['price_minor'] + 0);
    }

    public function test_maximum_price_round_trips(): void
    {
        $id = $this->repo->save($this->valid(['price' => '999.999.999,99']));
        $this->assertSame(99999999999, (int) $this->repo->find($id)['price_minor']);
    }

    public function test_price_errors(): void
    {
        foreach (['', 'abc', '-5', '1,234', '1000000000'] as $bad) {
            $this->assertSame(['price' => 'item.err_price'], $this->errors($this->valid(['price' => $bad])), $bad);
        }
        $this->assertSame(0, $this->rows('items'));
    }

    public function test_only_allowed_vat_rates(): void
    {
        $this->assertSame(['vat_bp' => 'item.err_vat'], $this->errors($this->valid(['vat_bp' => '1500'])));
        $this->assertSame(['vat_bp' => 'item.err_vat'], $this->errors($this->valid(['vat_bp' => 1500])));
        $this->assertSame(['vat_bp' => 'item.err_vat'], $this->errors($this->valid(['vat_bp' => 'abc'])));
        $this->assertSame(['vat_bp' => 'item.err_vat'], $this->errors($this->valid(['vat_bp' => ''])));
        $this->assertSame(0, $this->rows('items'));
        foreach ([1900, 700, 0, '700', '0'] as $ok) {
            $id = $this->repo->save($this->valid(['vat_bp' => $ok]));
            $this->assertSame((int) $ok, (int) $this->repo->find($id)['vat_bp']);
        }
    }

    public function test_allow_lists_for_type_unit_currency(): void
    {
        $this->assertSame(['type' => 'item.err_type'], $this->errors($this->valid(['type' => 'gadget'])));
        $this->assertSame(['unit' => 'item.err_unit'], $this->errors($this->valid(['unit' => 'parsec'])));
        $this->assertSame(['currency' => 'item.err_currency'], $this->errors($this->valid(['currency' => 'CHF'])));
        foreach (Units::KEYS as $unit) {
            $this->repo->save($this->valid(['unit' => $unit]));
        }
        $this->assertSame(7, $this->rows('items'));
        $this->assertSame(['hour', 'day', 'month', 'piece', 'flat', 'km', 'kg'], Units::KEYS);
    }

    public function test_at_least_one_name_required(): void
    {
        $this->assertSame(['names' => 'item.err_names'], $this->errors($this->valid(['name_de' => '', 'name_pl' => ' ', 'name_en' => ''])));
        $this->assertSame(0, $this->rows('items'));
        $id = $this->repo->save($this->valid(['name_de' => '', 'name_pl' => '', 'name_en' => ' Only English ']));
        $this->assertSame(['en'], array_keys($this->repo->find($id)['names']));
        $this->assertSame('Only English', $this->repo->find($id)['names']['en']['name']);
    }

    public function test_name_and_description_length_limits(): void
    {
        $this->assertSame(['name_pl' => 'item.err_name_length'], $this->errors($this->valid(['name_pl' => str_repeat('ł', 191)])));
        $this->repo->save($this->valid(['name_pl' => str_repeat('ł', 190)]));
        $this->assertSame(['description_en' => 'item.err_description'], $this->errors($this->valid(['description_en' => str_repeat('x', 2001)])));
        $this->repo->save($this->valid(['description_en' => str_repeat('x', 2000)]));
    }

    public function test_name_falls_back_to_german(): void
    {
        $id = $this->repo->save($this->valid(['name_pl' => '', 'name_en' => '']));
        $item = $this->repo->find($id);
        $this->assertSame('Beratung', $this->repo->nameFor($item, 'pl'));
        $this->assertSame('Beratung', $this->repo->nameFor($item, 'en'));
        $this->assertSame('Beratung', $this->repo->nameFor($item, 'de'));
    }

    public function test_name_falls_back_to_first_available_without_german(): void
    {
        $id = $this->repo->save($this->valid(['name_de' => '', 'name_pl' => 'Doradztwo', 'name_en' => 'Consulting']));
        $item = $this->repo->find($id);
        $this->assertSame('Consulting', $this->repo->nameFor($item, 'en'));
        $this->assertSame('Doradztwo', $this->repo->nameFor($item, 'de'), 'first available when German is missing too');
        $this->assertSame('', $this->repo->nameFor(['names' => []], 'de'));
    }

    public function test_list_has_display_name_for_ui_language(): void
    {
        $this->repo->save($this->valid());
        $this->repo->save($this->valid(['name_de' => 'Zeta', 'name_pl' => '', 'name_en' => '']));
        $this->assertSame(['Beratung', 'Zeta'], array_column($this->repo->list(), 'display_name'));
        $this->assertSame(['Consulting', 'Zeta'], array_column($this->inLang('en')->list(), 'display_name'));
        $this->assertSame(['Doradztwo', 'Zeta'], array_column($this->inLang('pl')->list(), 'display_name'));
    }

    public function test_removing_a_language_name_removes_its_row(): void
    {
        $id = $this->repo->save($this->valid());
        $this->assertSame(3, $this->rows('item_names'));
        $this->repo->save(['name_pl' => ''], $id);
        $this->assertSame(['de', 'en'], array_keys($this->repo->find($id)['names']));
        $this->assertSame(2, $this->rows('item_names'));
    }

    public function test_update_keeps_missing_fields_and_updates_names_in_place(): void
    {
        $id = $this->repo->save($this->valid(['description_de' => 'Alt']));
        $before = $this->repo->find($id);
        $this->repo->save(['name_de' => 'Beratung neu', 'price' => '10,00'], $id);
        $after = $this->repo->find($id);
        $this->assertSame('Beratung neu', $after['names']['de']['name']);
        $this->assertSame('Alt', $after['names']['de']['description'], 'description untouched when not given');
        $this->assertSame('Consulting', $after['names']['en']['name']);
        $this->assertSame(1000, (int) $after['price_minor']);
        $this->assertSame($before['unit'], $after['unit']);
        $this->assertSame($before['vat_bp'], $after['vat_bp']);
        $this->assertSame(3, $this->rows('item_names'), 'no duplicate rows');
    }

    public function test_company_id_and_id_in_input_are_ignored(): void
    {
        $id = $this->repo->save($this->valid(['company_id' => $this->companyB, 'id' => 999, 'created_at' => '2000-01-01']));
        $item = $this->repo->find($id);
        $this->assertSame($this->companyA, (int) $item['company_id']);
        $this->assertNotSame(999, $id);
        $this->assertNotSame('2000-01-01', substr((string) $item['created_at'], 0, 10));
        $this->assertSame([$this->companyA], array_map('intval', array_column($this->db->all('SELECT DISTINCT company_id FROM item_names'), 'company_id')));
    }

    public function test_save_is_transactional(): void
    {
        $this->db->exec("CREATE TRIGGER fail_names BEFORE INSERT ON item_names BEGIN SELECT RAISE(ABORT, 'boom'); END");
        try {
            $this->repo->save($this->valid());
            $this->fail('Expected failure');
        } catch (\PDOException) {
        }
        $this->assertSame(0, $this->rows('items'), 'item row rolled back');
        $this->assertSame(0, $this->rows('item_names'));
    }

    public function test_failed_update_rolls_back_everything(): void
    {
        $id = $this->repo->save($this->valid());
        $this->db->exec("CREATE TRIGGER fail_del BEFORE DELETE ON item_names BEGIN SELECT RAISE(ABORT, 'boom'); END");
        try {
            $this->repo->save(['price' => '1,00', 'name_pl' => ''], $id);
            $this->fail('Expected failure');
        } catch (\PDOException) {
        }
        $item = $this->repo->find($id);
        $this->assertSame(45000, (int) $item['price_minor']);
        $this->assertSame('Doradztwo', $item['names']['pl']['name']);
    }

    public function test_validation_failure_on_update_changes_nothing(): void
    {
        $id = $this->repo->save($this->valid());
        $this->assertSame(['vat_bp' => 'item.err_vat'], $this->errors(['vat_bp' => '1500', 'name_de' => 'X'], $id));
        $this->assertSame('Beratung', $this->repo->find($id)['names']['de']['name']);
    }

    public function test_set_active_and_active_only_list(): void
    {
        $a = $this->repo->save($this->valid());
        $b = $this->repo->save($this->valid(['name_de' => 'Zubehör', 'name_pl' => '', 'name_en' => '']));
        $this->repo->setActive($b, false);
        $this->assertSame(0, (int) $this->repo->find($b)['is_active']);
        $this->assertCount(2, $this->repo->list(), 'register shows inactive items');
        $active = $this->repo->list('', true);
        $this->assertSame([$a], array_map(fn ($i) => (int) $i['id'], $active));
        $this->repo->setActive($b, true);
        $this->assertCount(2, $this->repo->list('', true));
    }

    public function test_search_across_languages_and_letters(): void
    {
        $this->repo->save($this->valid());
        $this->repo->save($this->valid(['name_de' => 'Übersetzung', 'name_pl' => 'Tłumaczenie', 'name_en' => 'Translation']));
        $this->repo->save($this->valid(['name_de' => 'Schloss', 'name_pl' => 'Żółć', 'name_en' => 'Lock']));
        $names = fn (string $q): array => array_column($this->repo->list($q), 'display_name');
        $this->assertSame(['Beratung'], $names('consult'), 'English name found in a German UI');
        $this->assertSame(['Beratung'], $names('DORADZ'));
        $this->assertSame(['Übersetzung'], $names('tłumacz'));
        $this->assertSame(['Übersetzung'], $names('TŁUMACZ'));
        $this->assertSame(['Übersetzung'], $names('Tłumacz'));
        $this->assertSame(['Übersetzung'], $names('übers'));
        $this->assertSame(['Übersetzung'], $names('ÜBERS'));
        $this->assertSame(['Schloss'], $names('żółć'));
        $this->assertSame(['Schloss'], $names('ŻÓŁĆ'));
        $this->assertSame([], $names('nichts'));
        $this->assertCount(3, $this->repo->list('%'), '% is not a wildcard');
        $this->assertCount(3, $this->repo->list('   '));
        $this->assertSame([], $names("x' OR '1'='1"));
        $this->assertCount(1, $this->repo->list('Beratung', true));
    }

    public function test_search_active_only_combination(): void
    {
        $a = $this->repo->save($this->valid());
        $this->repo->setActive($a, false);
        $this->assertCount(1, $this->repo->list('beratung'));
        $this->assertCount(0, $this->repo->list('beratung', true));
    }

    public function test_isolation_other_company_is_invisible_and_untouchable(): void
    {
        $a = $this->repo->save($this->valid());
        $b = $this->repoB->save($this->valid(['name_de' => 'Geheim B']));

        $this->assertNull($this->repo->find($b));
        $this->assertSame([$a], array_map(fn ($i) => (int) $i['id'], $this->repo->list()));
        $this->assertSame([], $this->repo->list('Geheim'));
        $this->assertSame([], $this->repo->list('Geheim', true));

        $this->repo->setActive($b, false);
        $this->assertSame(1, (int) $this->repoB->find($b)['is_active']);

        try {
            $this->repo->save($this->valid(['name_de' => 'Hacked', 'name_pl' => 'Hacked']), $b);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        $itemB = $this->repoB->find($b);
        $this->assertSame('Geheim B', $itemB['names']['de']['name']);
        $this->assertSame('Doradztwo', $itemB['names']['pl']['name']);
        $this->assertSame(3, count($itemB['names']));
        $this->assertSame(6, $this->rows('item_names'));
        $this->assertSame(3, (int) $this->db->one('SELECT COUNT(*) c FROM item_names WHERE company_id = ? AND item_id = ?', [$this->companyB, $b])['c']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM item_names WHERE company_id = ? AND item_id = ?', [$this->companyA, $b])['c'], 'A cannot attach names to B\'s item');
    }

    public function test_unknown_id_throws_for_save(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->save($this->valid(), 424242);
    }

    public function test_set_active_unknown_id_is_a_noop(): void
    {
        $this->repo->setActive(424242, false);
        $this->assertSame(0, $this->rows('items'));
    }
}
