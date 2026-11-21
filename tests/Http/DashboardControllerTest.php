<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\ArraySessionStore;
use App\Core\Db;
use App\Core\Migrator;
use App\Core\Request;
use App\Domain\DocumentService;
use App\Domain\NumberSequences;
use App\Money\Formatter;
use App\Money\Money;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Tenant\CompanyContext;
use Tests\Support\HookPdo;
use Tests\Support\RecordingStatement;

/**
 * Dashboard (Task 22): the four cards, the recent panel with its tabs, the empty state, per-currency
 * display, isolation, escaping, query count and the plan box of the sidebar. The figures themselves
 * are proven in Tests\Repo\ReportRepoTest. Fixed clock: 2026-11-24 10:00 UTC (11:00 Berlin).
 */
final class DashboardControllerTest extends HttpTestCase
{
    private const NOW = 1_795_514_400;

    private int $userId;
    private string $secret;
    private int $companyA;
    private int $companyB;
    private int $customerA;
    private int $customerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = self::NOW;
        $this->boot();
    }

    /** Fresh users/companies on the current $this->db, logged in as the owner of company A. */
    private function boot(): void
    {
        $u = $this->createUser();
        $this->userId = $u['id'];
        $this->secret = $u['secret'];
        $this->companyA = $this->company('Muster Service GmbH');
        $this->companyB = $this->company('Fremd AG');
        $this->addMember($this->userId, $this->companyA);
        $this->customerA = $this->customer($this->companyA, 'Nordlicht Werbung UG');
        $this->customerB = $this->customer($this->companyB, 'Fremdkunde');
        $this->loginFully($this->secret);
    }

    private function company(string $name): int
    {
        $this->db->exec(
            'INSERT INTO companies (name, address, vat_id, tax_number, iban, access_status) VALUES (?, ?, ?, ?, ?, ?)',
            [$name, "Musterweg 1\n10115 Berlin", 'DE987654321', '27/123/45678', 'DE89370400440532013000', 'active']
        );
        return $this->db->lastId();
    }

    private function customer(int $company, string $name, string $currency = 'EUR', string $country = 'DE'): int
    {
        $this->db->exec(
            'INSERT INTO customers (company_id, name, address, country, is_company, default_lang, default_currency, payment_days) VALUES (?, ?, ?, ?, 1, ?, ?, 14)',
            [$company, $name, "Kundenweg 1\n10115 Berlin", $country, 'de', $currency]
        );
        return $this->db->lastId();
    }

    private function svc(int $company): DocumentService
    {
        $ctx = CompanyContext::forUser($this->db, $this->userId, $company);
        $clock = fn (): int => $this->now;
        return new DocumentService($this->db, $ctx, new NumberSequences($this->db, $ctx, $clock), new DocumentRepo($this->db, $ctx), new CustomerRepo($this->db, $ctx), $clock);
    }

    private function line(int $unit): array
    {
        return ['name' => 'Leistung', 'unit' => 'hour', 'qty_milli' => 1000, 'unit_minor' => $unit, 'discount_bp' => 0, 'vat_bp' => 1900];
    }

    /** Issued invoice, net $net (+ 19 %), issued 2026-10-01. */
    private function invoice(string $due, int $net = 10000, int $company = 0, int $customer = 0, array $extra = []): int
    {
        $company = $company ?: $this->companyA;
        $svc = $this->svc($company);
        $id = $svc->createDraft('invoice', $extra + [
            'customer_id' => $customer ?: ($company === $this->companyA ? $this->customerA : $this->customerB),
            'issue_date' => '2026-10-01', 'due_date' => $due, 'lines' => [$this->line($net)],
        ]);
        $svc->issue($id, new \DateTimeImmutable('@' . $this->now));
        return $id;
    }

    private function pay(int $invoice, int $amount, string $day = '2026-11-02'): void
    {
        $this->svc($this->companyA)->addPayment($invoice, $amount, new \DateTimeImmutable($day . ' 12:00:00', new \DateTimeZone('Europe/Berlin')));
    }

    /** The scenario of the card tests: EUR 438,00 open (3), 119,00 overdue (1), 200,00 due soon (1), 157,00 paid (2) + one overdue PLN invoice. */
    private function scenario(): void
    {
        $this->invoice('2026-11-10');
        $b = $this->invoice('2026-11-26', 20000);
        $this->pay($b, 3800);
        $this->invoice('2026-12-31');
        $d = $this->invoice('2026-11-30');
        $this->pay($d, 11900, '2026-11-05');
    }

    private function plnCustomer(): int
    {
        return $this->customer($this->companyA, 'Kowalski Transport', 'PLN', 'PL');
    }

    private function money(int $minor, string $cur, string $lang = 'de'): string
    {
        return htmlspecialchars((new Formatter())->money(Money::of($minor, $cur), $lang), ENT_QUOTES);
    }

    /** Inner text of the card with data-card="$key". */
    private function card(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('#<article class="card [^"]*" data-card="' . $key . '">(.*?)</article>#s', $html, $m), $key);
        return $m[1];
    }

    private function body(string $path = '/', array $server = []): string
    {
        $query = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $r = $this->app->handle(new Request('GET', (string) parse_url($path, PHP_URL_PATH), query: $query, server: $server + ['REMOTE_ADDR' => self::IP]));
        $this->assertSame(200, $r->status);
        return $r->body;
    }

    // ---- access ----

    public function test_unauthenticated_goes_to_login_and_user_without_company_gets_403(): void
    {
        $this->store = new ArraySessionStore();
        $this->app = $this->makeApp();
        $this->assertRedirect('/login', $this->get('/'));
        $u = $this->createUser('solo@example.pl');
        $this->loginFully($u['secret'], 'solo@example.pl');
        $this->assertSame(403, $this->get('/')->status);
    }

    public function test_role_user_sees_the_dashboard(): void
    {
        $this->db->exec('UPDATE company_users SET role = ? WHERE user_id = ?', ['user', $this->userId]);
        $this->scenario();
        $this->assertStringContainsString('data-card="open"', $this->body());
    }

    // ---- cards ----

    public function test_cards_show_amounts_counts_and_colours(): void
    {
        $this->scenario();
        $html = $this->body();
        $this->assertStringContainsString('card-y', $html);
        $open = $this->card($html, 'open');
        $this->assertStringContainsString($this->money(43800, 'EUR'), $open);
        $this->assertStringContainsString('Rechnungen: 3', $open);
        $late = $this->card($html, 'overdue');
        $this->assertStringContainsString($this->money(11900, 'EUR'), $late);
        $this->assertStringContainsString('stat stat-late', $late);
        $this->assertMatchesRegularExpression('#<a class="strong-link" href="/dunning">Rechnungen: 1 · Mahnung vorschlagen</a>#u', $late);
        $soon = $this->card($html, 'due_in_7');
        $this->assertStringContainsString($this->money(20000, 'EUR'), $soon);
        $this->assertStringContainsString('Rechnungen: 1', $soon);
        $paid = $this->card($html, 'paid_month');
        $this->assertStringContainsString($this->money(15700, 'EUR'), $paid);
        $this->assertStringContainsString('Zahlungen: 2', $paid);
        $this->assertStringContainsString('Bezahlt (November)', $paid);
        foreach (['card-late' => 'overdue', 'card-soon' => 'due_in_7', 'card-ok' => 'paid_month'] as $class => $key) {
            $this->assertStringContainsString('class="card ' . $class . '" data-card="' . $key . '"', $html);
        }
    }

    public function test_zero_amounts_and_no_overdue_link_or_red(): void
    {
        $this->invoice('2026-12-31');
        $html = $this->body();
        $zero = $this->money(0, 'EUR');
        foreach (['overdue', 'due_in_7', 'paid_month'] as $key) {
            $card = $this->card($html, $key);
            $this->assertStringContainsString($zero, $card, $key);
            $this->assertStringNotContainsString('stat-late', $card);
            $this->assertStringNotContainsString('/dunning', $card);
        }
        $this->assertStringContainsString('0,00' . "\u{a0}" . '€', html_entity_decode($this->card($html, 'overdue')));
        $this->assertStringContainsString('Rechnungen: 0', $this->card($html, 'overdue'));
    }

    public function test_other_currencies_are_listed_below_and_never_summed(): void
    {
        $this->scenario();
        $this->invoice('2026-11-01', 500000, 0, $this->plnCustomer(), ['eur_rate' => '4.2650']);   // 5.950,00 PLN overdue
        $html = $this->body();
        $pln = '+ ' . $this->money(595000, 'PLN');
        $open = $this->card($html, 'open');
        $this->assertStringContainsString($this->money(43800, 'EUR'), $open, 'EUR figure unchanged');
        $this->assertStringContainsString($pln, $open);
        $this->assertStringContainsString($pln, $this->card($html, 'overdue'));
        $this->assertStringContainsString($this->money(11900, 'EUR'), $this->card($html, 'overdue'));
        $this->assertStringNotContainsString('PLN', $this->card($html, 'due_in_7'));
        $this->assertStringNotContainsString('+ ', $this->card($html, 'paid_month'));
        $this->assertStringContainsString('Rechnungen: 2', $this->card($html, 'overdue'), 'a count of documents covers all currencies');
        $this->assertStringNotContainsString($this->money(43800 + 595000, 'EUR'), $html);
    }

    public function test_primary_currency_follows_the_company(): void
    {
        $this->db->exec("UPDATE companies SET default_currency = 'PLN' WHERE id = ?", [$this->companyA]);
        $this->invoice('2026-11-10', 100000, 0, $this->plnCustomer(), ['eur_rate' => '4.2650']);
        $this->invoice('2026-11-10');
        $open = $this->card($this->body(), 'open');
        $this->assertStringContainsString($this->money(119000, 'PLN'), $open);
        $this->assertStringContainsString('+ ' . $this->money(11900, 'EUR'), $open);
    }

    public function test_dunning_letters_do_not_change_the_cards(): void
    {
        $inv = $this->invoice('2026-11-01');
        $before = $this->body();
        $this->db->exec(
            "INSERT INTO documents (company_id, type, status, number, related_document_id, dunning_level, dunning_claim_minor, issue_date, lang, currency, net_minor, vat_minor, gross_minor, buyer_name)
             VALUES (?, 'dunning', 'issued', 'MA-1', ?, 1, 987654, '2026-11-20', 'de', 'EUR', 0, 0, 0, 'Nordlicht Werbung UG')",
            [$this->companyA, $inv]
        );
        $after = $this->body();
        foreach (['open', 'overdue', 'due_in_7', 'paid_month'] as $key) {
            $this->assertSame($this->card($before, $key), $this->card($after, $key), $key);
        }
        $this->assertStringNotContainsString('MA-1', $after, 'dunning letters are not in the recent panel');
        $this->assertStringNotContainsString($this->money(987654, 'EUR'), $after);
    }

    public function test_company_b_never_influences_company_a(): void
    {
        $this->scenario();
        $before = $this->body();
        $this->addMember($this->userId, $this->companyB);
        $this->invoice('2026-11-01', 9_999_900, $this->companyB);
        $b = $this->invoice('2026-11-26', 100000, $this->companyB);
        $this->svc($this->companyB)->addPayment($b, 5000, new \DateTimeImmutable('2026-11-03 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $after = $this->body();
        foreach (['open', 'overdue', 'due_in_7', 'paid_month'] as $key) {
            $this->assertSame($this->card($before, $key), $this->card($after, $key), $key);
        }
        $this->assertStringNotContainsString('Fremdkunde', $after);
        // and the other way round: company B sees only its own figures
        $this->post('/company/switch', ['company_id' => (string) $this->companyB]);
        $html = $this->body();
        $this->assertStringContainsString($this->money(11_899_881 + 119_000 - 5_000, 'EUR'), $this->card($html, 'open'));
        $this->assertStringNotContainsString('Nordlicht', $html);
    }

    public function test_paid_month_label_and_subtitle_use_the_ui_language(): void
    {
        $this->scenario();
        $expected = ['de' => ['Bezahlt (November)', 'November 2026 · Muster Service GmbH'],
            'pl' => ['Opłacone (listopad)', 'listopad 2026 · Muster Service GmbH'],
            'en' => ['Paid (November)', 'November 2026 · Muster Service GmbH']];
        foreach ($expected as $lang => [$label, $subtitle]) {
            $this->post('/lang', ['lang' => $lang, 'back' => '/']);
            $html = $this->body();
            $this->assertStringContainsString('<html lang="' . $lang . '"', $html);
            $this->assertStringContainsString($label, $this->card($html, 'paid_month'), $lang);
            $this->assertStringContainsString($subtitle, $html, $lang);
            $this->assertStringContainsString($this->money(43800, 'EUR', $lang), $this->card($html, 'open'), $lang);
        }
        // the Berlin month decides, not UTC: 2026-11-30 23:30 UTC is already December in Berlin
        $this->now = (new \DateTimeImmutable('2026-11-30 23:30:00', new \DateTimeZone('UTC')))->getTimestamp();
        $this->store = new ArraySessionStore();
        $this->app = $this->makeApp();
        $this->loginFully($this->secret);
        $this->post('/lang', ['lang' => 'de', 'back' => '/']);
        $this->assertStringContainsString('Bezahlt (Dezember)', $this->body());
    }

    // ---- recent panel ----

    public function test_recent_panel_rows_statuses_links_and_chips(): void
    {
        $open = $this->invoice('2026-12-20');
        $overdue = $this->invoice('2026-11-01');
        $paid = $this->invoice('2026-12-20');
        $this->pay($paid, 11900);
        $partial = $this->invoice('2026-12-20');
        $this->pay($partial, 100);
        $credited = $this->invoice('2026-12-20');
        $svc = $this->svc($this->companyA);
        $cn = $svc->createCorrection($credited);
        $svc->updateDraft($cn, ['issue_date' => '2026-11-02', 'lines' => [$this->line(10000)]]);
        $svc->issue($cn, new \DateTimeImmutable('@' . $this->now));
        $draft = $svc->createDraft('invoice', ['customer_id' => $this->customerA, 'lines' => [$this->line(500)]]);
        $html = $this->body();
        $row = function (int $id) use ($html): string {
            $this->assertSame(1, preg_match('#<tr data-doc-row>(?:(?!</tr>).)*href="/invoices/' . $id . '"(?:(?!</tr>).)*</tr>#s', $html, $m), (string) $id);
            return $m[0];
        };
        $this->assertStringContainsString('chip-sent">Offen<', $row($open));
        $this->assertStringContainsString('chip-late">Überfällig<', $row($overdue));
        $this->assertStringContainsString('chip-ok">Bezahlt<', $row($paid));
        $this->assertStringContainsString('chip-soon">Teilweise bezahlt<', $row($partial));
        $this->assertStringContainsString('chip-draft">Vollständig korrigiert<', $row($credited));
        $this->assertStringContainsString('chip-sent">Ausgestellt<', $row($cn));
        $this->assertStringContainsString($this->money(-11900, 'EUR'), $row($cn), 'credit note shows its signed amount');
        $this->assertStringContainsString('chip-draft">Entwurf<', $row($draft));
        $this->assertStringContainsString('>Entwurf</a>', $row($draft), 'a draft has no number');
        $this->assertStringContainsString('Nordlicht Werbung UG', $row($open));
        $this->assertStringContainsString('Rechnungskorrektur', $row($cn));
        $this->assertStringContainsString('20.12.2026', $row($open));
    }

    public function test_recent_panel_limit_order_and_tabs(): void
    {
        $svc = $this->svc($this->companyA);
        $ids = [];
        for ($i = 1; $i <= 12; $i++) {
            $id = $svc->createDraft('invoice', ['customer_id' => $this->customerA, 'issue_date' => sprintf('2026-11-%02d', $i), 'due_date' => '2026-12-30', 'lines' => [$this->line(10000)]]);
            $svc->issue($id, new \DateTimeImmutable('@' . $this->now));
            $ids[$i] = $id;
        }
        $offer = $svc->createDraft('offer', ['customer_id' => $this->customerA, 'issue_date' => '2026-11-13', 'valid_until' => '2026-11-20', 'lines' => [$this->line(20000)]]);
        $svc->issue($offer, new \DateTimeImmutable('@' . $this->now));
        $hrefs = fn (string $html): array => $this->hrefs($html);
        $all = $hrefs($this->body('/?tab=all'));
        $this->assertSame(['/offers/' . $offer, ...array_map(fn (int $i): string => '/invoices/' . $ids[$i], range(12, 4))], $all);
        $this->assertSame($all, $hrefs($this->body('/')), 'default tab is all');
        $invoices = $hrefs($this->body('/?tab=invoices'));
        $this->assertSame(array_map(fn (int $i): string => '/invoices/' . $ids[$i], range(12, 3)), $invoices);
        $offersHtml = $this->body('/?tab=offers');
        $this->assertSame(['/offers/' . $offer], $hrefs($offersHtml));
        $this->assertStringContainsString('chip-soon">Abgelaufen<', $offersHtml);
        foreach (['/?tab=nope', '/?tab=', '/?tab=INVOICES', '/?tab=invoices%00'] as $bad) {
            $this->assertSame($all, $hrefs($this->body($bad)), $bad);
        }
        $html = $this->body('/?tab=offers');
        $this->assertStringContainsString('<a href="/?tab=offers" class="is-on" aria-current="page">Angebote</a>', $html);
        $this->assertStringContainsString('<a href="/?tab=all">Alle</a>', $html);
        $this->assertStringContainsString('<a href="/?tab=invoices">Rechnungen</a>', $html);
    }

    /** @return list<string> */
    private function hrefs(string $html): array
    {
        preg_match_all('#<tr data-doc-row>\s*<td><a class="strong-link" href="([^"]+)"#', $html, $m);
        return $m[1];
    }

    public function test_offer_statuses_in_the_panel(): void
    {
        $svc = $this->svc($this->companyA);
        $mk = function (array $extra, ?string $status = null) use ($svc): int {
            $id = $svc->createDraft('offer', $extra + ['customer_id' => $this->customerA, 'lines' => [$this->line(10000)]]);
            $svc->issue($id, new \DateTimeImmutable('@' . $this->now));
            if ($status !== null) {
                $svc->setOfferStatus($id, $status);
            }
            return $id;
        };
        $ids = ['open' => $mk(['issue_date' => '2026-11-20', 'valid_until' => '2026-12-20']), 'sent' => $mk(['issue_date' => '2026-11-19'], 'sent'),
            'accepted' => $mk(['issue_date' => '2026-11-18'], 'accepted'), 'rejected' => $mk(['issue_date' => '2026-11-17'], 'rejected'),
            'expired' => $mk(['issue_date' => '2026-11-10', 'valid_until' => '2026-11-23'])];
        $draft = $svc->createDraft('offer', ['customer_id' => $this->customerA, 'lines' => [$this->line(100)]]);
        $html = $this->body('/?tab=offers');
        $want = ['open' => 'chip-sent">Offen<', 'sent' => 'chip-sent">Gesendet<', 'accepted' => 'chip-ok">Angenommen<', 'rejected' => 'chip-late">Abgelehnt<', 'expired' => 'chip-soon">Abgelaufen<'];
        foreach ($want as $k => $needle) {
            $this->assertSame(1, preg_match('#<tr data-doc-row>(?:(?!</tr>).)*href="/offers/' . $ids[$k] . '"(?:(?!</tr>).)*</tr>#s', $html, $m), $k);
            $this->assertStringContainsString($needle, $m[0], $k);
            $this->assertStringContainsString('Angebot', $m[0]);
        }
        $this->assertStringContainsString('chip-draft">Entwurf<', $html);
        $this->assertStringContainsString('href="/offers/' . $draft . '"', $html);
    }

    public function test_customer_names_are_escaped(): void
    {
        $evil = $this->customer($this->companyA, '<script>alert(1)</script> & "Söhne"');
        $this->invoice('2026-12-20', 10000, 0, $evil);
        $html = $this->body();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Söhne&quot;', $html);
        $this->assertStringContainsString('class="wrap"', $html, 'long names may wrap');
    }

    public function test_empty_state_for_a_company_without_documents(): void
    {
        $html = $this->body();
        $this->assertStringContainsString('class="card empty-state"', $html);
        $this->assertStringContainsString('Willkommen bei Faktura', $html);
        $this->assertStringNotContainsString('data-card=', $html);
        $this->assertStringContainsString('href="/invoices/new"', $html);
        $this->assertStringContainsString('href="/offers/new"', $html);
        // a filtered tab of a company WITH documents is not the empty state
        $this->invoice('2026-12-20');
        $offers = $this->body('/?tab=offers');
        $this->assertStringNotContainsString('empty-state', $offers);
        $this->assertStringContainsString('Keine Belege in dieser Ansicht.', $offers);
        $this->assertStringContainsString('data-card="open"', $offers);
    }

    public function test_only_a_draft_still_leaves_the_empty_state(): void
    {
        $this->svc($this->companyA)->createDraft('invoice', ['customer_id' => $this->customerA, 'lines' => [$this->line(100)]]);
        $html = $this->body();
        $this->assertStringNotContainsString('empty-state', $html);
        $this->assertStringContainsString($this->money(0, 'EUR'), $this->card($html, 'open'));
    }

    public function test_quick_actions_and_promo_slot(): void
    {
        $this->scenario();
        $html = $this->body();
        $this->assertStringContainsString('<a class="btn btn-primary" href="/invoices/new">', $html);
        $this->assertStringContainsString('Neue Rechnung', $html);
        $this->assertStringContainsString('href="/offers/new"', $html);
        $this->assertStringNotContainsString('class="promo"', $html);
        $this->app = $this->makeApp(['promo_enabled' => true]);
        $promo = $this->body();
        $this->assertStringContainsString('<aside class="promo" aria-label="Partner-Hinweis">', $promo);
        $this->assertStringContainsString('Platz für einen Partner-Hinweis', $promo);
    }

    public function test_garbage_query_parameters_never_give_a_500(): void
    {
        $this->scenario();
        $long = str_repeat('x', 20000);
        foreach ([['tab' => ['a' => ['b']]], ['tab' => $long], ['tab' => "\xff\xfe"], ['tab' => 'offers', 'x' => ['y']], ['page' => '-1', 'tab' => ['']]] as $q) {
            $r = $this->app->handle(new Request('GET', '/', query: $q, server: ['REMOTE_ADDR' => self::IP]));
            $this->assertSame(200, $r->status, json_encode(array_keys($q)));
            $this->assertStringContainsString('data-card="open"', $r->body);
        }
        $this->assertSame('no-store', $this->get('/')->headers['Cache-Control'] ?? null);
    }

    // ---- plan box ----

    public function test_plan_box_shows_plan_and_this_months_issued_documents_without_a_limit(): void
    {
        $this->scenario();                                       // issued 2026-10-01: not this month
        $this->db->exec("INSERT INTO documents (company_id, type, status, issue_date, lang, currency) VALUES (?, 'invoice', 'issued', '2026-11-03', 'de', 'EUR')", [$this->companyA]);
        $this->db->exec("INSERT INTO documents (company_id, type, status, issue_date, lang, currency) VALUES (?, 'offer', 'issued', '2026-11-04', 'de', 'EUR')", [$this->companyA]);
        $this->db->exec("INSERT INTO documents (company_id, type, status, issue_date, lang, currency) VALUES (?, 'invoice', 'draft', '2026-11-04', 'de', 'EUR')", [$this->companyA]);
        $html = $this->body();
        $this->assertStringContainsString('<b>Free-Plan</b>', $html);
        $this->assertStringContainsString('Belege im November: 2', $html);
        $this->assertStringNotContainsString('<progress', $html);
        $this->assertStringNotContainsString('von 3', $html);
        $this->assertStringNotContainsString('class="plan-upgrade"', $html);
        $this->post('/lang', ['lang' => 'pl', 'back' => '/']);
        $this->assertStringContainsString('Dokumenty (listopad): 2', $this->body());
        $this->post('/lang', ['lang' => 'en', 'back' => '/']);
        $this->assertStringContainsString('Documents in November: 2', $this->body());
        foreach (['standard' => 'Standard plan', 'pro' => 'Pro plan'] as $plan => $label) {
            $this->db->exec('UPDATE companies SET plan = ? WHERE id = ?', [$plan, $this->companyA]);
            $this->assertStringContainsString('<b>' . $label . '</b>', $this->body(), $plan);
        }
    }

    public function test_plan_box_bar_only_when_a_limit_is_configured_and_upgrade_link_only_with_url(): void
    {
        $this->db->exec("INSERT INTO documents (company_id, type, status, issue_date, lang, currency) VALUES (?, 'invoice', 'issued', '2026-11-03', 'de', 'EUR')", [$this->companyA]);
        $this->app = $this->makeApp(['plan_limits' => ['free' => 3], 'upgrade_url' => 'https://example.com/upgrade?a=1&b=2']);
        $html = $this->body();
        $this->assertStringContainsString('<progress class="plan-bar" max="3" value="1"', $html);
        $this->assertStringContainsString('1 von 3 Belegen', $html);
        $this->assertStringContainsString('<a class="plan-upgrade" href="https://example.com/upgrade?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">', $html);
        // the bar never overflows its maximum
        for ($i = 0; $i < 4; $i++) {
            $this->db->exec("INSERT INTO documents (company_id, type, status, issue_date, lang, currency) VALUES (?, 'offer', 'issued', '2026-11-05', 'de', 'EUR')", [$this->companyA]);
        }
        $this->assertStringContainsString('max="3" value="3"', $this->body());
        // a limit for another plan does not apply; non-https and hostile URLs are ignored
        foreach (['http://example.com/up', 'javascript:alert(1)', '//evil.example', 'https://x.example/"onmouseover="a', ''] as $bad) {
            $this->app = $this->makeApp(['plan_limits' => ['pro' => 5], 'upgrade_url' => $bad]);
            $html = $this->body();
            $this->assertStringNotContainsString('<progress', $html);
            $this->assertStringNotContainsString('plan-upgrade', $html, $bad);
        }
        // the upgrade link is a free-plan thing
        $this->db->exec("UPDATE companies SET plan = 'pro' WHERE id = ?", [$this->companyA]);
        $this->app = $this->makeApp(['upgrade_url' => 'https://example.com/upgrade']);
        $this->assertStringNotContainsString('plan-upgrade', $this->body());
    }

    public function test_plan_box_hidden_for_an_admin_without_company_context(): void
    {
        $this->db->exec('UPDATE users SET is_admin = 1 WHERE id = ?', [$this->userId]);
        $this->db->exec('DELETE FROM company_users WHERE user_id = ?', [$this->userId]);
        $r = $this->get('/admin/companies');
        $this->assertSame(200, $r->status);
        $this->assertStringNotContainsString('plan-box', $r->body);
        $this->assertStringNotContainsString('data-plan-usage', $r->body);
    }

    // ---- performance ----

    public function test_query_count_does_not_depend_on_the_number_of_invoices(): void
    {
        $pdo = new HookPdo('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [RecordingStatement::class]);
        $this->db = new Db($pdo, 'sqlite');
        (new Migrator($this->db, __DIR__ . '/../../migrations'))->run();
        $this->store = new ArraySessionStore();
        $this->app = $this->makeApp();
        $this->boot();

        $countFor = function (): int {
            RecordingStatement::$log = [];
            $r = $this->get('/');
            $this->assertSame(200, $r->status);
            return count(RecordingStatement::$log);
        };
        for ($i = 0; $i < 2; $i++) {
            $this->invoice('2026-11-' . sprintf('%02d', 10 + $i));
        }
        $this->get('/');                                   // the first request resolves the company; count the steady state
        $few = $countFor();
        for ($i = 0; $i < 58; $i++) {
            $id = $this->invoice('2026-12-' . sprintf('%02d', 1 + $i % 28), 10000 + $i);
            if ($i % 3 === 0) {
                $this->pay($id, 100 + $i);
            }
        }
        $many = $countFor();
        $this->assertSame($few, $many, 'query count is independent of the number of invoices');
        $this->assertLessThanOrEqual(16, $many, 'a handful of queries for the whole page');
        $this->assertSame(60, (int) $this->db->one('SELECT COUNT(*) AS c FROM documents WHERE company_id = ?', [$this->companyA])['c']);
    }
}
