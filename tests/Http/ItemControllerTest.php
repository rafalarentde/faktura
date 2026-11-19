<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\Request;
use App\Core\Response;
use PHPUnit\Framework\Attributes\DataProvider;

final class ItemControllerTest extends HttpTestCase
{
    private int $companyA;
    private int $companyB;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyA = $this->createCompany('A GmbH');
        $this->companyB = $this->createCompany('B GmbH');
        $user = $this->createUser();
        $this->secret = $user['secret'];
        $this->addMember($user['id'], $this->companyA);
    }

    private function login(string $lang = 'de'): void
    {
        $this->loginFully($this->secret);
        if ($lang !== 'de') {
            $this->db->exec('UPDATE users SET ui_lang = ?', [$lang]);
            $this->app->session()->set('lang', $lang);
        }
    }

    /** @param array<string, string> $names lang => name */
    private function item(int $company, array $names, array $extra = []): int
    {
        $row = ['company_id' => $company] + $extra + ['type' => 'service', 'unit' => 'hour', 'price_minor' => 45000, 'currency' => 'EUR', 'vat_bp' => 1900, 'is_active' => 1];
        $this->db->exec(
            'INSERT INTO items (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')',
            array_values($row)
        );
        $id = $this->db->lastId();
        foreach ($names as $lang => $name) {
            $this->db->exec('INSERT INTO item_names (company_id, item_id, lang, name) VALUES (?, ?, ?, ?)', [$company, $id, $lang, $name]);
        }
        return $id;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return $this->db->one('SELECT * FROM items WHERE id = ?', [$id]);
    }

    /** @return array<string, string> */
    private function valid(array $over = []): array
    {
        return $over + ['name_de' => 'Beratung', 'name_pl' => 'Doradztwo', 'name_en' => 'Consulting', 'type' => 'service',
            'unit' => 'hour', 'price' => '450,00', 'currency' => 'EUR', 'vat_bp' => '1900'];
    }

    public function test_unauthenticated_redirects_to_login(): void
    {
        $this->assertRedirect('/login', $this->get('/items'));
        $this->assertRedirect('/login', $this->get('/items/1'));
        $this->assertRedirect('/login', $this->post('/items', $this->valid()));
        $this->assertRedirect('/login', $this->post('/items/1', $this->valid()));
        $this->assertRedirect('/login', $this->post('/items/1/active', ['active' => '0']));
    }

    public function test_user_without_company_gets_403(): void
    {
        $other = $this->createUser('solo@example.pl');
        $this->loginFully($other['secret'], 'solo@example.pl');
        $this->assertSame(403, $this->get('/items')->status);
    }

    public function test_register_form_below_and_nav_item_enabled(): void
    {
        $this->login();
        $this->item($this->companyA, ['de' => 'Beratung']);
        $this->item($this->companyB, ['de' => 'Fremd AG']);
        $r = $this->get('/items');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Beratung', $r->body);
        $this->assertStringNotContainsString('Fremd AG', $r->body);
        $this->assertStringContainsString('data-nav="items"', $r->body);
        $this->assertStringContainsString('action="/items"', $r->body);
        $this->assertStringContainsString('name="_csrf"', $r->body);
        foreach (['name_de', 'name_pl', 'name_en', 'type', 'unit', 'vat_bp', 'price', 'currency'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $r->body, $field);
        }
        $this->assertLessThan(strpos($r->body, 'id="item-form"'), strpos($r->body, 'Beratung'), 'register above the form');
        $this->assertStringNotContainsString(' style=', $r->body);
    }

    public function test_register_shows_price_unit_vat_and_type(): void
    {
        $this->login();
        $this->item($this->companyA, ['de' => 'Beratung'], ['price_minor' => 123456, 'unit' => 'day', 'vat_bp' => 700, 'type' => 'goods']);
        $body = str_replace(["\u{00A0}", "\u{202F}"], ' ', $this->get('/items')->body);
        $this->assertStringContainsString('1.234,56 €', $body);
        $this->assertStringContainsString('Tag', $body);
        $this->assertStringContainsString('7 %', $body);
        $this->assertStringContainsString('Ware', $body);
    }

    public function test_show_fills_edit_form(): void
    {
        $this->login();
        $id = $this->item($this->companyA, ['de' => 'Beratung', 'pl' => 'Doradztwo'], ['price_minor' => 123456, 'unit' => 'day', 'vat_bp' => 700]);
        $r = $this->get('/items/' . $id);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('action="/items/' . $id . '"', $r->body);
        $this->assertStringContainsString('value="Doradztwo"', $r->body);
        $this->assertStringContainsString('value="1234,56"', $r->body);
        $this->assertMatchesRegularExpression('/<option value="day" selected>/', $r->body);
        $this->assertMatchesRegularExpression('/<option value="700" selected>/', $r->body);
    }

    public function test_search(): void
    {
        $this->login();
        $this->item($this->companyA, ['de' => 'Beratung', 'pl' => 'Doradztwo', 'en' => 'Consulting']);
        $this->item($this->companyA, ['de' => 'Übersetzung', 'pl' => 'Tłumaczenie']);
        $r = $this->app->handle(new Request('GET', '/items', query: ['q' => 'tłumacz'], server: ['REMOTE_ADDR' => self::IP]));
        $this->assertStringContainsString('Übersetzung', $r->body);
        $this->assertStringNotContainsString('Beratung', $r->body);
        $r = $this->app->handle(new Request('GET', '/items', query: ['q' => 'zzz'], server: ['REMOTE_ADDR' => self::IP]));
        $this->assertStringContainsString('class="empty-row"', $r->body);
    }

    public function test_create_in_de_stores_minor_units_in_context_company(): void
    {
        $this->login();
        $r = $this->post('/items', $this->valid(['company_id' => (string) $this->companyB, 'id' => '999', 'is_active' => '0']));
        $id = (int) $this->db->one('SELECT id FROM items')['id'];
        $this->assertRedirect('/items/' . $id . '?saved=1', $r);
        $row = $this->row($id);
        $this->assertSame(45000, (int) $row['price_minor']);
        $this->assertSame($this->companyA, (int) $row['company_id']);
        $this->assertSame(1, (int) $row['is_active']);
        $this->assertSame(3, (int) $this->db->one('SELECT COUNT(*) c FROM item_names WHERE company_id = ?', [$this->companyA])['c']);
        $shown = $this->app->handle(new Request('GET', '/items/' . $id, query: ['saved' => '1'], server: ['REMOTE_ADDR' => self::IP]));
        $this->assertStringContainsString('class="notice"', $shown->body);
    }

    public function test_create_in_en_parses_point_decimal(): void
    {
        $this->login('en');
        $this->post('/items', $this->valid(['price' => '450.00']));
        $this->assertSame(45000, (int) $this->db->one('SELECT price_minor FROM items')['price_minor']);
        $this->post('/items', $this->valid(['price' => '1,234.50']));
        $this->assertSame([45000, 123450], array_map('intval', array_column($this->db->all('SELECT price_minor FROM items ORDER BY id'), 'price_minor')));
    }

    public function test_csrf_missing_gives_419(): void
    {
        $this->login();
        $id = $this->item($this->companyA, ['de' => 'X']);
        $this->assertSame(419, $this->post('/items', $this->valid(), csrf: false)->status);
        $this->assertSame(419, $this->post('/items/' . $id, $this->valid(), csrf: false)->status);
        $this->assertSame(419, $this->post('/items/' . $id . '/active', ['active' => '0'], csrf: false)->status);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM items')['c']);
        $this->assertSame(1, (int) $this->row($id)['is_active']);
    }

    public function test_validation_errors_keep_typed_values_and_return_422(): void
    {
        $this->login();
        $r = $this->post('/items', $this->valid(['name_de' => '<b>x"y</b>', 'price' => '12,345', 'vat_bp' => '1500', 'unit' => 'day', 'name_pl' => '']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('value="&lt;b&gt;x&quot;y&lt;/b&gt;"', $r->body);
        $this->assertStringContainsString('value="12,345"', $r->body, 'typed price string kept');
        $this->assertMatchesRegularExpression('/<option value="day" selected>/', $r->body);
        $this->assertStringContainsString('class="warn" role="alert"', $r->body);
        $this->assertStringNotContainsString('item.err_', $r->body);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM items')['c']);
    }

    public function test_no_name_at_all_is_422(): void
    {
        $this->login();
        $r = $this->post('/items', $this->valid(['name_de' => '', 'name_pl' => '', 'name_en' => '']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('role="alert"', $r->body);
    }

    public function test_update_validation_error_keeps_saved_row_unchanged(): void
    {
        $this->login();
        $id = $this->item($this->companyA, ['de' => 'Alt']);
        $r = $this->post('/items/' . $id, $this->valid(['price' => 'abc']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('value="abc"', $r->body);
        $this->assertSame(45000, (int) $this->row($id)['price_minor']);
        $this->assertSame('Alt', $this->db->one('SELECT name FROM item_names WHERE item_id = ? AND lang = ?', [$id, 'de'])['name']);
    }

    public function test_update_changes_names_and_price_and_can_drop_a_language(): void
    {
        $this->login();
        $id = $this->item($this->companyA, ['de' => 'Alt', 'pl' => 'Stary']);
        $r = $this->post('/items/' . $id, $this->valid(['name_de' => 'Neu', 'name_pl' => '', 'name_en' => 'New', 'price' => '99,50']));
        $this->assertRedirect('/items/' . $id . '?saved=1', $r);
        $this->assertSame(9950, (int) $this->row($id)['price_minor']);
        $names = $this->db->all('SELECT lang, name FROM item_names WHERE item_id = ? ORDER BY lang', [$id]);
        $this->assertSame([['lang' => 'de', 'name' => 'Neu'], ['lang' => 'en', 'name' => 'New']], $names);
    }

    public function test_html_in_name_is_escaped(): void
    {
        $this->login();
        $this->post('/items', $this->valid(['name_de' => '<script>alert(1)</script> & Co']));
        $id = (int) $this->db->one('SELECT id FROM items')['id'];
        $this->assertSame('<script>alert(1)</script> & Co', $this->db->one('SELECT name FROM item_names WHERE item_id = ? AND lang = ?', [$id, 'de'])['name']);
        foreach (['/items', '/items/' . $id] as $path) {
            $body = $this->get($path)->body;
            $this->assertStringNotContainsString('<script>alert(1)', $body);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co', $body);
        }
    }

    public function test_other_company_item_is_404_everywhere(): void
    {
        $this->login();
        $b = $this->item($this->companyB, ['de' => 'Secret B', 'pl' => 'Tajne']);
        $missing = $this->get('/items/99999');
        $this->assertSame(404, $missing->status);
        $foreign = $this->get('/items/' . $b);
        $this->assertSame(404, $foreign->status);
        $strip = fn (string $b): string => preg_replace('/<input type="hidden"[^>]*>/', '', $b);
        $this->assertSame($strip($missing->body), $strip($foreign->body), 'same response as a missing id');
        $this->assertStringNotContainsString('Secret B', $foreign->body);
        $this->assertSame(404, $this->post('/items/' . $b, $this->valid(['name_de' => 'Hacked']))->status);
        $this->assertSame(404, $this->post('/items/' . $b . '/active', ['active' => '0'])->status);
        $this->assertSame(404, $this->post('/items/99999/active', ['active' => '0'])->status);
        $this->assertSame(404, $this->post('/items/99999', $this->valid())->status);
        $row = $this->row($b);
        $this->assertSame(1, (int) $row['is_active']);
        $this->assertSame(45000, (int) $row['price_minor']);
        $this->assertSame($this->companyB, (int) $row['company_id']);
        $this->assertSame(['Secret B', 'Tajne'], array_column($this->db->all('SELECT name FROM item_names WHERE item_id = ? ORDER BY lang ASC', [$b]), 'name'));
        $this->assertSame(2, (int) $this->db->one('SELECT COUNT(*) c FROM item_names')['c']);
    }

    public function test_deactivate_and_activate(): void
    {
        $this->login();
        $id = $this->item($this->companyA, ['de' => 'Beratung']);
        $this->assertRedirect('/items', $this->post('/items/' . $id . '/active', ['active' => '0']));
        $this->assertSame(0, (int) $this->row($id)['is_active']);
        $body = $this->get('/items')->body;
        $this->assertStringContainsString('Beratung', $body, 'inactive items stay in the register');
        $this->assertStringContainsString('Inaktiv', $body);
        $this->assertStringContainsString('Aktivieren', $body);
        $this->assertRedirect('/items', $this->post('/items/' . $id . '/active', ['active' => '1']));
        $this->assertSame(1, (int) $this->row($id)['is_active']);
        $this->assertStringContainsString('Deaktivieren', $this->get('/items')->body);
    }

    public function test_save_does_not_change_active_flag(): void
    {
        $this->login();
        $id = $this->item($this->companyA, ['de' => 'Beratung'], ['is_active' => 0]);
        $this->post('/items/' . $id, $this->valid(['is_active' => '1']));
        $this->assertSame(0, (int) $this->row($id)['is_active']);
    }

    public function test_no_delete_route(): void
    {
        $this->login();
        $id = $this->item($this->companyA, ['de' => 'X']);
        $this->assertSame(404, $this->post('/items/' . $id . '/delete')->status);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM items')['c']);
    }

    /** @return iterable<string, array{string, string, string, string}> title, goods label, hour, piece */
    public static function languages(): iterable
    {
        yield 'de' => ['de', 'Artikel & Leistungen', 'Std.', 'Stk.'];
        yield 'pl' => ['pl', 'Towary i usługi', 'godz.', 'szt.'];
        yield 'en' => ['en', 'Products & services', 'hr', 'pc'];
    }

    #[DataProvider('languages')]
    public function test_renders_in_every_language_with_translated_units(string $lang, string $title, string $hour, string $piece): void
    {
        $this->login($lang);
        $id = $this->item($this->companyA, ['de' => 'Beratung', 'pl' => 'Doradztwo', 'en' => 'Consulting'], ['unit' => 'hour']);
        foreach (['/items', '/items/' . $id] as $path) {
            $r = $this->get($path);
            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('<h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1>', $r->body);
            $this->assertStringContainsString('>' . $hour . '<', $r->body);
            $this->assertStringContainsString('>' . $piece . '<', $r->body);
            $this->assertDoesNotMatchRegularExpression('/\b(item|unit)\.[a-z_]+/', strip_tags($r->body), 'no untranslated keys');
        }
        $expected = ['de' => 'Beratung', 'pl' => 'Doradztwo', 'en' => 'Consulting'][$lang];
        $this->assertStringContainsString('<a class="strong-link" href="/items/' . $id . '">' . $expected . '</a>', $this->get('/items')->body);
    }

    public function test_price_input_uses_ui_language_format(): void
    {
        $this->login('en');
        $id = $this->item($this->companyA, ['de' => 'X'], ['price_minor' => 45000]);
        $this->assertStringContainsString('value="450.00"', $this->get('/items/' . $id)->body);
    }
}
