<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\Request;
use App\Core\Response;
use App\Vies\ViesStatus;
use Tests\Support\FakeViesClient;

final class CustomerControllerTest extends HttpTestCase
{
    private FakeViesClient $vies;
    private int $companyA;
    private int $companyB;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vies = new FakeViesClient(ViesStatus::Valid);
        $this->app = $this->makeApp(['vies_client' => $this->vies]);
        $this->companyA = $this->createCompany('A GmbH');
        $this->companyB = $this->createCompany('B GmbH');
        $user = $this->createUser();
        $this->secret = $user['secret'];
        $this->addMember($user['id'], $this->companyA);
    }

    private function login(): void
    {
        $this->loginFully($this->secret);
    }

    private function customer(int $company, string $name, array $extra = []): int
    {
        $row = ['company_id' => $company, 'name' => $name] + $extra + ['country' => 'DE'];
        $this->db->exec(
            'INSERT INTO customers (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')',
            array_values($row)
        );
        return $this->db->lastId();
    }

    /** @param array<string, string> $query */
    private function getQ(string $path, array $query): Response
    {
        return $this->app->handle(new Request('GET', $path, query: $query, server: ['REMOTE_ADDR' => self::IP]));
    }

    private function row(int $id): array
    {
        return $this->db->one('SELECT * FROM customers WHERE id = ?', [$id]);
    }

    private function valid(array $over = []): array
    {
        return $over + ['name' => 'Acme Sp. z o.o.', 'address' => "Ul. 1\n00-001 Łódź", 'country' => 'PL', 'is_company' => '1',
            'vat_id' => 'PL1234567890', 'email' => 'x@example.pl', 'default_lang' => 'pl', 'default_currency' => 'PLN',
            'payment_days' => '14', 'notes' => ''];
    }

    public function test_unauthenticated_redirects_to_login(): void
    {
        $this->assertRedirect('/login', $this->get('/customers'));
        $this->assertRedirect('/login', $this->get('/customers/1'));
        $this->assertRedirect('/login', $this->post('/customers', $this->valid()));
        $this->assertRedirect('/login', $this->post('/customers/1/vies'));
    }

    public function test_user_without_company_gets_403(): void
    {
        $other = $this->createUser('solo@example.pl');
        $this->loginFully($other['secret'], 'solo@example.pl');
        $this->assertSame(403, $this->get('/customers')->status);
    }

    public function test_register_and_empty_form_render_with_nav_item(): void
    {
        $this->login();
        $this->customer($this->companyA, 'Müller GmbH');
        $this->customer($this->companyB, 'Fremd AG');
        $r = $this->get('/customers');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Müller GmbH', $r->body);
        $this->assertStringNotContainsString('Fremd AG', $r->body);
        $this->assertStringContainsString('data-nav="customers"', $r->body);
        $this->assertStringContainsString('action="/customers"', $r->body);
        $this->assertStringContainsString('name="_csrf"', $r->body);
        $this->assertStringNotContainsString('VIES', $r->body, 'no VIES button for a customer that is not saved yet');
        $this->assertLessThan(strpos($r->body, 'id="customer-form"'), strpos($r->body, 'Müller GmbH'), 'register above the form');
    }

    public function test_search(): void
    {
        $this->login();
        $this->customer($this->companyA, 'Müller GmbH');
        $this->customer($this->companyA, 'Łódź Sp.');
        $this->customer($this->companyA, 'Acme');
        $r = $this->getQ('/customers', ['q' => 'MÜLLER']);
        $this->assertStringContainsString('Müller GmbH', $r->body);
        $this->assertStringNotContainsString('Acme', $r->body);
        $this->assertStringNotContainsString('Łódź', $r->body);
        $none = $this->getQ('/customers', ['q' => 'zzz']);
        $this->assertStringContainsString('Keine Kunden gefunden', $none->body);
        $this->assertStringContainsString('value="zzz"', $none->body);
    }

    public function test_create_redirects_and_stores_in_context_company(): void
    {
        $this->login();
        $r = $this->post('/customers', $this->valid(['company_id' => $this->companyB]));
        $this->assertSame(302, $r->status);
        $id = (int) $this->db->one('SELECT id FROM customers')['id'];
        $this->assertRedirect('/customers/' . $id . '?saved=1', $r);
        $this->assertSame($this->companyA, (int) $this->row($id)['company_id']);
        $this->assertSame('PL1234567890', $this->row($id)['vat_id']);
        $page = $this->getQ('/customers/' . $id, ['saved' => '1']);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('value="Acme Sp. z o.o."', $page->body);
        $this->assertStringContainsString('Gespeichert.', $page->body);
        $this->assertStringContainsString('action="/customers/' . $id . '/vies"', $page->body);
    }

    public function test_csrf_missing_gives_419(): void
    {
        $this->login();
        $id = $this->customer($this->companyA, 'X');
        $this->assertSame(419, $this->post('/customers', $this->valid(), csrf: false)->status);
        $this->assertSame(419, $this->post('/customers/' . $id, $this->valid(), csrf: false)->status);
        $this->assertSame(419, $this->post('/customers/' . $id . '/vies', [], csrf: false)->status);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM customers')['c']);
    }

    public function test_validation_errors_keep_input_and_return_422(): void
    {
        $this->login();
        $r = $this->post('/customers', $this->valid(['name' => '', 'email' => 'nope', 'address' => 'Keep <me>', 'payment_days' => '999']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte gib einen Namen ein', $r->body);
        $this->assertStringContainsString('Bitte gib eine gültige E-Mail-Adresse ein.', $r->body);
        $this->assertStringContainsString('Zahlungsziel muss zwischen', $r->body);
        $this->assertStringContainsString('value="nope"', $r->body);
        $this->assertStringContainsString('Keep &lt;me&gt;', $r->body);
        $this->assertStringContainsString('value="PL1234567890"', $r->body);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM customers')['c']);
    }

    public function test_update_validation_error_keeps_saved_row_unchanged(): void
    {
        $this->login();
        $id = $this->customer($this->companyA, 'Old');
        $r = $this->post('/customers/' . $id, $this->valid(['name' => ' ']));
        $this->assertSame(422, $r->status);
        $this->assertSame('Old', $this->row($id)['name']);
    }

    public function test_update(): void
    {
        $this->login();
        $id = $this->customer($this->companyA, 'Old');
        $this->assertRedirect('/customers/' . $id . '?saved=1', $this->post('/customers/' . $id, $this->valid(['name' => 'New'])));
        $this->assertSame('New', $this->row($id)['name']);
    }

    public function test_html_in_name_is_escaped(): void
    {
        $this->login();
        $this->post('/customers', $this->valid(['name' => '<script>alert(1)</script> & Co']));
        $id = (int) $this->db->one('SELECT id FROM customers')['id'];
        $this->assertSame('<script>alert(1)</script> & Co', $this->row($id)['name']);
        foreach (['/customers', '/customers/' . $id] as $path) {
            $body = $this->get($path)->body;
            $this->assertStringNotContainsString('<script>alert(1)', $body);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co', $body);
        }
    }

    public function test_other_company_customer_is_404_everywhere(): void
    {
        $this->login();
        $b = $this->customer($this->companyB, 'Secret B', ['vat_id' => 'DE123456789']);
        $this->assertSame(404, $this->get('/customers/' . $b)->status);
        $this->assertStringNotContainsString('Secret B', $this->get('/customers/' . $b)->body);
        $this->assertSame(404, $this->post('/customers/' . $b, $this->valid(['name' => 'Hacked']))->status);
        $this->assertSame(404, $this->post('/customers/' . $b . '/vies')->status);
        $this->assertSame(404, $this->get('/customers/99999')->status);
        $this->assertSame([], $this->vies->calls, 'no VIES call for a foreign customer');
        $row = $this->row($b);
        $this->assertSame('Secret B', $row['name']);
        $this->assertNull($row['vies_status']);
        $this->assertSame($this->companyB, (int) $row['company_id']);
    }

    public function test_no_delete_route(): void
    {
        $this->login();
        $id = $this->customer($this->companyA, 'X');
        $this->assertSame(404, $this->post('/customers/' . $id . '/delete')->status);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM customers')['c']);
    }

    public function test_vies_button_stores_status_and_time(): void
    {
        $this->login();
        $id = $this->customer($this->companyA, 'Acme', ['country' => 'PL', 'vat_id' => 'PL1234567890']);
        $page = $this->get('/customers/' . $id);
        $this->assertStringContainsString('Noch nicht geprüft', $page->body);

        $r = $this->post('/customers/' . $id . '/vies');
        $this->assertRedirect('/customers/' . $id, $r);
        $this->assertSame([['PL', '1234567890']], $this->vies->calls);
        $row = $this->row($id);
        $this->assertSame('valid', $row['vies_status']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->now), $row['vies_checked_at']);

        $body = $this->get('/customers/' . $id)->body;
        $this->assertStringContainsString('Gültig · heute geprüft → Reverse Charge', $body);
    }

    public function test_vies_valid_for_german_customer_is_no_reverse_charge(): void
    {
        $this->login();
        $id = $this->customer($this->companyA, 'Inland', ['country' => 'DE', 'vat_id' => 'DE123456789']);
        $this->post('/customers/' . $id . '/vies');
        $body = $this->get('/customers/' . $id)->body;
        $this->assertStringNotContainsString('Reverse Charge', $body);
        $this->assertStringContainsString('Gültig · geprüft am', $body);
    }

    public function test_vies_valid_line_uses_date_when_not_today(): void
    {
        $this->login();
        $id = $this->customer($this->companyA, 'Acme', ['country' => 'PL', 'vat_id' => 'PL1', 'vies_status' => 'valid', 'vies_checked_at' => '2020-01-05 10:00:00']);
        $body = $this->get('/customers/' . $id)->body;
        $this->assertStringContainsString('geprüft am 05.01.2020 → Reverse Charge', $body);
    }

    public function test_vies_invalid_shows_warning(): void
    {
        $this->login();
        $this->vies->result = ViesStatus::Invalid;
        $id = $this->customer($this->companyA, 'Acme', ['country' => 'PL', 'vat_id' => 'PL1234567890']);
        $this->post('/customers/' . $id . '/vies');
        $this->assertSame('invalid', $this->row($id)['vies_status']);
        $body = $this->get('/customers/' . $id)->body;
        $this->assertStringContainsString('Ungültig laut VIES', $body);
        $this->assertStringNotContainsString('→ Reverse Charge', $body);
    }

    public function test_vies_unavailable_is_stored_and_shown_as_try_later(): void
    {
        $this->login();
        $this->vies->result = ViesStatus::Unavailable;
        $id = $this->customer($this->companyA, 'Acme', ['country' => 'PL', 'vat_id' => 'PL1234567890']);
        $this->post('/customers/' . $id . '/vies');
        $row = $this->row($id);
        $this->assertSame('unavailable', $row['vies_status']);
        $this->assertNotNull($row['vies_checked_at']);
        $this->assertStringContainsString('nicht erreichbar', $this->get('/customers/' . $id)->body);
    }

    public function test_vies_without_vat_id_is_422_without_calling_service(): void
    {
        $this->login();
        $id = $this->customer($this->companyA, 'NoVat');
        $r = $this->post('/customers/' . $id . '/vies');
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Trage zuerst eine USt-IdNr. ein', $r->body);
        $this->assertSame([], $this->vies->calls);
    }

    public function test_greek_customer_uses_el_prefix_for_vies(): void
    {
        $this->login();
        $id = $this->customer($this->companyA, 'Hellas', ['country' => 'GR', 'vat_id' => 'EL123456789']);
        $this->post('/customers/' . $id . '/vies');
        $this->assertSame([['EL', '123456789']], $this->vies->calls);
    }

    public function test_changing_vat_id_resets_vies_in_the_ui(): void
    {
        $this->login();
        $id = $this->customer($this->companyA, 'Acme', ['country' => 'PL', 'vat_id' => 'PL1234567890', 'vies_status' => 'valid', 'vies_checked_at' => '2026-01-01 00:00:00']);
        $this->post('/customers/' . $id, $this->valid(['vat_id' => 'PL9999999999']));
        $this->assertNull($this->row($id)['vies_status']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function languages(): iterable
    {
        yield 'de' => ['de', 'Kunden'];
        yield 'pl' => ['pl', 'Klienci'];
        yield 'en' => ['en', 'Customers'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('languages')]
    public function test_renders_in_every_language(string $lang, string $title): void
    {
        $this->login();
        $id = $this->customer($this->companyA, 'Acme', ['country' => 'PL', 'vat_id' => 'PL1']);
        $this->db->exec('UPDATE users SET ui_lang = ?', [$lang]);
        $this->app->session()->set('lang', $lang);
        foreach (['/customers', '/customers/' . $id] as $path) {
            $r = $this->get($path);
            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('<h1>' . $title . '</h1>', $r->body);
            $this->assertDoesNotMatchRegularExpression('/customer\.[a-z_]+/', strip_tags($r->body), 'no untranslated keys');
        }
    }
}
