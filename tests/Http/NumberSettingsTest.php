<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\Response;
use App\Domain\NumberSequences;
use App\Tenant\CompanyContext;

final class NumberSettingsTest extends HttpTestCase
{
    private int $company;
    /** @var array{id: int, secret: string} */
    private array $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = (int) strtotime('2026-11-18 12:00:00 UTC');
        $this->company = $this->createCompany('Muster GmbH');
        $this->owner = $this->createUser('owner@example.com');
        $this->addMember($this->owner['id'], $this->company, 'owner');
    }

    private function loginOwner(): void
    {
        $this->loginFully($this->owner['secret'], 'owner@example.com');
    }

    private function seq(?int $company = null): NumberSequences
    {
        return new NumberSequences($this->db, CompanyContext::forUser($this->db, $this->owner['id'], $company ?? $this->company), fn (): int => $this->now);
    }

    private function berlin(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Berlin'));
    }

    /** The form as the page renders it (defaults), with overrides. @return array<string, string> */
    private function form(array $over = []): array
    {
        return $over + [
            'pattern_invoice' => 'RE-{YYYY}-{NNN}', 'reset_invoice' => '1', 'next_invoice' => '1', 'next_shown_invoice' => '1',
            'pattern_offer' => 'AN-{YYYY}-{NNN}', 'reset_offer' => '1', 'next_offer' => '1', 'next_shown_offer' => '1',
            'pattern_dunning' => 'MA-{YY}{MM}-{NNN}', 'reset_dunning' => '1', 'next_dunning' => '1', 'next_shown_dunning' => '1',
            'pattern_credit_note' => 'KO-{YYYY}-{NNN}', 'reset_credit_note' => '1', 'next_credit_note' => '1', 'next_shown_credit_note' => '1',
        ];
    }

    private function audits(): array
    {
        return $this->db->all("SELECT * FROM audit_log WHERE action = 'settings.numbers_save' ORDER BY id");
    }

    // ---- access ----

    public function test_unauthenticated_goes_to_login(): void
    {
        $this->assertRedirect('/login', $this->get('/settings/numbers'));
        $this->assertRedirect('/login', $this->post('/settings/numbers', $this->form()));
    }

    public function test_role_user_gets_403_and_cannot_change_anything(): void
    {
        $u = $this->createUser('member@example.com');
        $this->addMember($u['id'], $this->company, 'user');
        $this->loginFully($u['secret'], 'member@example.com');
        $this->assertSame(403, $this->get('/settings/numbers')->status);
        $this->assertSame(403, $this->post('/settings/numbers', $this->form(['pattern_invoice' => 'HACK-{N}']))->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS c FROM number_formats')['c']);
        $this->assertStringNotContainsString('/settings/numbers', $this->get('/settings/security')->body, 'tab hidden for role user');
    }

    public function test_post_without_csrf_is_419(): void
    {
        $this->loginOwner();
        $this->assertSame(419, $this->post('/settings/numbers', $this->form(['pattern_invoice' => 'X-{N}']), csrf: false)->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS c FROM number_formats')['c']);
    }

    // ---- page ----

    public function test_owner_sees_defaults_with_preview_legend_and_tab(): void
    {
        $this->loginOwner();
        $r = $this->get('/settings/numbers');
        $this->assertSame(200, $r->status);
        foreach (['RE-{YYYY}-{NNN}', 'AN-{YYYY}-{NNN}', 'MA-{YY}{MM}-{NNN}', 'KO-{YYYY}-{NNN}'] as $p) {
            $this->assertStringContainsString('value="' . $p . '"', $r->body);
        }
        foreach (['RE-2026-001', 'AN-2026-001', 'MA-2611-001', 'KO-2026-001'] as $p) {
            $this->assertStringContainsString('<b class="mono">' . $p . '</b>', $r->body);
        }
        $this->assertStringContainsString('Nächste Nummer', $r->body);
        $this->assertStringContainsString('{YYYY} = Jahr', $r->body, 'token legend');
        $this->assertStringContainsString('href="/settings/numbers" aria-current="page"', $r->body);
        $this->assertStringContainsString('name="_csrf"', $r->body);
        $this->assertSame(4, substr_count($r->body, 'name="reset_') , 'one checkbox per doc type');
        $this->assertSame(4, substr_count($r->body, ' checked'));
    }

    public function test_page_uses_the_berlin_business_date_not_utc(): void
    {
        $this->now = (int) strtotime('2026-12-31 23:30:00 UTC'); // already 2027-01-01 in Berlin
        $this->loginOwner();
        $this->assertStringContainsString('RE-2027-001', $this->get('/settings/numbers')->body);
    }

    // ---- save ----

    public function test_owner_saves_formats_and_sees_the_preview(): void
    {
        $this->loginOwner();
        $r = $this->post('/settings/numbers', $this->form([
            'pattern_invoice' => 'FV/{YY}/{MM}/{NNNN}', 'next_invoice' => '42',
            'pattern_offer' => 'ANG-{N}', 'reset_offer' => '',
        ]));
        $this->assertSame(200, $r->status, 'raising the next number shows the skip note');
        $this->assertSame(['pattern' => 'FV/{YY}/{MM}/{NNNN}', 'reset_yearly' => true], $this->seq()->format('invoice'));
        $this->assertSame(['pattern' => 'ANG-{N}', 'reset_yearly' => false], $this->seq()->format('offer'));
        $this->assertSame('FV/26/11/0042', $this->seq()->next('invoice', $this->berlin('2026-11-18')));

        $page = $this->app->handle(new \App\Core\Request('GET', '/settings/numbers', query: ['saved' => '1'], server: ['REMOTE_ADDR' => self::IP]));
        $this->assertStringContainsString('Gespeichert.', $page->body);
        $this->assertStringContainsString('<b class="mono">FV/26/11/0043</b>', $page->body);
        $this->assertStringContainsString('<b class="mono">ANG-1</b>', $page->body);
        $this->assertStringContainsString('value="43"', $page->body);
    }

    public function test_raising_the_next_number_shows_the_skip_note(): void
    {
        $this->loginOwner();
        $r = $this->post('/settings/numbers', $this->form(['next_invoice' => '500']));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Die Nummern dazwischen werden übersprungen', $r->body);
        $this->assertStringContainsString('<b class="mono">RE-2026-500</b>', $r->body);
        $this->assertSame('RE-2026-500', $this->seq()->next('invoice', $this->berlin('2026-11-18')));
    }

    public function test_reset_without_year_is_saved_with_orange_warning(): void
    {
        $this->loginOwner();
        $r = $this->post('/settings/numbers', $this->form(['pattern_invoice' => 'INV-{NNNN}', 'reset_invoice' => '1']));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('<p class="warn-soft" role="status">Ohne Jahr im Format', $r->body);
        $this->assertFalse($this->seq()->format('invoice')['reset_yearly']);
        $this->assertStringNotContainsString('name="reset_invoice" value="1" checked', $r->body);
    }

    public function test_validation_errors_rerender_422_keep_typed_values_and_save_nothing(): void
    {
        $this->loginOwner();
        $r = $this->post('/settings/numbers', $this->form([
            'pattern_invoice' => 'FAKTURA-2026',
            'pattern_offer' => 'AN-{NNN}-{NN}',
            'pattern_dunning' => 'MA-{ABC}-{N}',
            'pattern_credit_note' => 'KO-{NNN',
            'next_invoice' => '77',
        ]));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('<p class="warn" role="alert">Es fehlt ein Zähler', $r->body);
        $this->assertStringContainsString('Es darf nur ein Zähler vorkommen', $r->body);
        $this->assertStringContainsString('Unbekannter Platzhalter', $r->body);
        $this->assertStringContainsString('Die geschweiften Klammern passen nicht zusammen', $r->body);
        $this->assertStringContainsString('value="FAKTURA-2026"', $r->body);
        $this->assertStringContainsString('value="KO-{NNN"', $r->body);
        $this->assertStringContainsString('value="77"', $r->body);
        $this->assertStringContainsString('Bitte prüfe die markierten Felder', $r->body);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS c FROM number_formats')['c'], 'all-or-nothing');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS c FROM number_counters')['c']);
        $this->assertSame([], $this->audits());
    }

    public function test_one_bad_row_rolls_back_the_valid_rows_too(): void
    {
        $this->loginOwner();
        $r = $this->post('/settings/numbers', $this->form(['pattern_invoice' => 'OK-{NNN}', 'pattern_offer' => 'BAD']));
        $this->assertSame(422, $r->status);
        $this->assertSame('RE-{YYYY}-{NNN}', $this->seq()->format('invoice')['pattern']);
        $this->assertStringContainsString('value="OK-{NNN}"', $r->body, 'typed value of the valid row is kept');
        $this->assertSame([], $this->audits());
    }

    public function test_next_number_below_issued_is_refused(): void
    {
        $this->loginOwner();
        $d = $this->berlin('2026-11-18');
        $this->seq()->next('invoice', $d);
        $this->seq()->next('invoice', $d);
        $this->seq()->next('invoice', $d);
        $r = $this->post('/settings/numbers', $this->form(['next_invoice' => '3', 'next_shown_invoice' => '4', 'pattern_invoice' => 'X-{NNN}']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('muss größer sein als die zuletzt vergebene', $r->body);
        $this->assertSame('RE-{YYYY}-{NNN}', $this->seq()->format('invoice')['pattern']);
        foreach (['abc', '-4', '0', '99999999999', '1.5', ' '] as $bad) {
            $r = $this->post('/settings/numbers', $this->form(['next_invoice' => $bad, 'next_shown_invoice' => '4']));
            if (trim($bad) === '') {
                $this->assertNotSame(422, $r->status, 'blank = unchanged');
                continue;
            }
            $this->assertSame(422, $r->status, $bad);
            $this->assertStringContainsString('ganze Zahl von 1 bis 9999999999', $r->body);
        }
        $this->assertSame(3, $this->seq()->lastCounter('invoice', $d));
    }

    public function test_untouched_next_number_does_not_fail_after_another_issue(): void
    {
        $this->loginOwner();
        $d = $this->berlin('2026-11-18');
        // the page showed 1, meanwhile 3 invoices were issued, the user only edits the offer format
        $this->seq()->next('invoice', $d);
        $this->seq()->next('invoice', $d);
        $this->seq()->next('invoice', $d);
        $r = $this->post('/settings/numbers', $this->form(['pattern_offer' => 'ANG-{YY}-{NNN}']));
        $this->assertRedirect('/settings/numbers?saved=1', $r);
        $this->assertSame(3, $this->seq()->lastCounter('invoice', $d), 'counter untouched');
        $this->assertSame('ANG-{YY}-{NNN}', $this->seq()->format('offer')['pattern']);
    }

    public function test_audit_rows_have_field_names_only(): void
    {
        $this->loginOwner();
        $this->post('/settings/numbers', $this->form(['pattern_invoice' => 'GEHEIM-{NNN}', 'next_invoice' => '9000', 'reset_dunning' => '']));
        $rows = $this->audits();
        $this->assertCount(2, $rows, 'one row per changed doc type');
        $byType = [];
        foreach ($rows as $row) {
            $this->assertSame($this->company, (int) $row['company_id']);
            $this->assertSame($this->owner['id'], (int) $row['user_id']);
            $this->assertStringNotContainsString('GEHEIM', (string) $row['detail']);
            $this->assertStringNotContainsString('9000', (string) $row['detail']);
            $d = json_decode((string) $row['detail'], true);
            $byType[$d['doc_type']] = $d['fields'];
        }
        $this->assertEqualsCanonicalizing(['pattern', 'reset_yearly', 'next_number'], $byType['invoice']);
        $this->assertSame(['reset_yearly'], $byType['dunning']);
    }

    public function test_pattern_with_html_is_escaped_everywhere(): void
    {
        $this->loginOwner();
        $r = $this->post('/settings/numbers', $this->form(['pattern_invoice' => '<b>{YYYY}{NNN}</b>']));
        $this->assertRedirect('/settings/numbers?saved=1', $r);
        $page = $this->get('/settings/numbers');
        $this->assertStringNotContainsString('<b>2026001</b>', $page->body);
        $this->assertStringNotContainsString('value="<b>', $page->body);
        $this->assertStringContainsString('value="&lt;b&gt;{YYYY}{NNN}&lt;/b&gt;"', $page->body);
        $this->assertStringContainsString('&lt;b&gt;2026001&lt;/b&gt;', $page->body);
        // the 422 path echoes typed values: escaped too
        $bad = $this->post('/settings/numbers', $this->form(['pattern_offer' => '"><script>alert(1)</script>']));
        $this->assertSame(422, $bad->status);
        $this->assertStringNotContainsString('<script>alert(1)', $bad->body);
        $this->assertStringContainsString('&lt;script&gt;', $bad->body);
    }

    public function test_crafted_fields_cannot_reach_other_companies_or_types(): void
    {
        $other = $this->createCompany('Other GmbH');
        $this->loginOwner();
        $b = $this->createUser('b@example.com');
        $this->addMember($b['id'], $other, 'owner');
        (new NumberSequences($this->db, CompanyContext::forUser($this->db, $b['id'], $other)))->next('invoice', $this->berlin('2026-11-18'));
        $this->post('/settings/numbers', $this->form([
            'company_id' => (string) $other, 'doc_type' => 'invoice', 'pattern_evil' => 'EVIL-{N}', 'pattern_invoice_x' => 'EVIL-{N}',
        ]) + ['id' => '1']);
        $this->assertSame(
            ['RE-{YYYY}-{NNN}'],
            array_column($this->db->all('SELECT pattern FROM number_formats WHERE company_id = ?', [$other]), 'pattern'),
            'only the default row its own next() created; nothing from the request'
        );
        $this->assertSame(1, (int) $this->db->one('SELECT last_value AS c FROM number_counters WHERE company_id = ?', [$other])['c']);
        foreach ($this->db->all('SELECT doc_type FROM number_formats') as $row) {
            $this->assertContains($row['doc_type'], ['invoice', 'offer', 'dunning', 'credit_note']);
        }
    }

    public function test_literal_year_shows_an_orange_warning_line(): void
    {
        $this->loginOwner();
        $r = $this->post('/settings/numbers', $this->form(['pattern_invoice' => 'RE-2026-{NNN}', 'reset_invoice' => '']));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('<p class="warn-soft" role="status">Das Format enthält eine feste Jahreszahl', $r->body);
        $ok = $this->post('/settings/numbers', $this->form(['pattern_invoice' => 'RE-{YYYY}-{NNN}']));
        $this->assertRedirect('/settings/numbers?saved=1', $ok);
    }
}
