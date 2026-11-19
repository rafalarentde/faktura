<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Http\Shell;

/** Application shell (layout.twig), design tokens, fonts, navigation, promo slot, dashboard. */
final class LayoutTest extends HttpTestCase
{
    private const ROOT = __DIR__ . '/../..';

    private function css(): string
    {
        $css = file_get_contents(self::ROOT . '/public/assets/app.css');
        $this->assertIsString($css);
        return $css;
    }

    /** Logs in a user with one company (so the dashboard renders) and returns the user. */
    private function loginWithCompany(string $email = 'jan@example.pl', bool $admin = false, string $company = 'Muster Service GmbH'): array
    {
        $u = $this->createUser($email);
        if ($admin) {
            $this->db->exec('UPDATE users SET is_admin = 1 WHERE id = ?', [$u['id']]);
        }
        $this->addMember($u['id'], $this->createCompany($company));
        $this->loginFully($u['secret'], $email);
        return $u;
    }

    public function test_css_defines_palette_tokens(): void
    {
        $css = $this->css();
        foreach (['#F5C400', '#f4f2ea', '#c4351f'] as $token) {
            $this->assertStringContainsString($token, $css);
        }
        $expected = [
            '--y' => '#F5C400', '--y2' => '#ffd633', '--k' => '#0f0f0f', '--k2' => '#1b1b1b', '--k3' => '#2a2a2a',
            '--bg' => '#f4f2ea', '--card' => '#ffffff', '--line' => '#e3e0d3', '--t' => '#2b2b2b', '--m' => '#636363',
            '--ok' => '#1f8a43', '--soon' => '#d68a00', '--late' => '#c4351f',
        ];
        foreach ($expected as $name => $value) {
            $this->assertMatchesRegularExpression('/' . preg_quote($name, '/') . '\s*:\s*' . preg_quote($value, '/') . '\s*;/', $css, $name);
        }
    }

    public function test_dark_and_light_tokens_are_both_defined(): void
    {
        $css = $this->css();
        $dark = ['--bg' => '#15171a', '--card' => '#191b1e', '--line' => '#2c2f33', '--t' => '#e9eaec', '--m' => '#a3a8ae'];
        // Explicit dark theme (toggle) ...
        $this->assertMatchesRegularExpression('/:root\[data-theme="dark"\]\s*\{[^}]*--bg\s*:\s*#15171a/', $css);
        // ... and the system preference when no theme was chosen.
        $this->assertMatchesRegularExpression('/@media\s*\(prefers-color-scheme:\s*dark\)\s*\{\s*:root:not\(\[data-theme="light"\]\)\s*\{[^}]*--bg\s*:\s*#15171a/', $css);
        foreach ($dark as $name => $value) {
            $this->assertMatchesRegularExpression('/' . preg_quote($name, '/') . '\s*:\s*' . preg_quote($value, '/') . '\s*;/', $css, $name);
        }
        $this->assertMatchesRegularExpression('/:root\s*\{[^}]*--bg\s*:\s*#f4f2ea/', $css);
    }

    public function test_css_documents_component_classes(): void
    {
        $css = $this->css();
        $header = substr($css, 0, (int) strpos($css, '*/'));
        $classes = ['.btn', '.btn-primary', '.btn-ghost', '.card', '.cards', '.panel', '.panel-head', '.table', '.tbl-wrap',
            '.chip', '.chip-ok', '.chip-soon', '.chip-late', '.chip-draft', '.chip-sent', '.form-grid', '.grid-2', '.grid-3',
            '.field', '.toggle', '.tabs', '.seg', '.hint', '.warn'];
        foreach ($classes as $class) {
            $this->assertStringContainsString($class, $header, "$class documented in the header comment");
            $this->assertMatchesRegularExpression('/' . preg_quote($class, '/') . '[\s,{:.>\[]/', substr($css, strlen($header)), "$class defined");
        }
        // Mobile breakpoint like the mockup.
        $this->assertStringContainsString('@media (max-width: 820px)', $css);
    }

    public function test_fonts_include_latin_ext_files(): void
    {
        $ext = glob(self::ROOT . '/public/assets/fonts/*-latin-ext.woff2') ?: [];
        $names = array_map('basename', $ext);
        foreach (['barlow-condensed-700-latin-ext.woff2', 'barlow-condensed-800-latin-ext.woff2', 'source-sans-3-400-latin-ext.woff2'] as $f) {
            $this->assertContains($f, $names);
        }
        foreach (['SourceSans3.ttf', 'BarlowCondensed-Bold.ttf', 'BarlowCondensed-ExtraBold.ttf'] as $f) {
            $this->assertFileExists(self::ROOT . '/resources/fonts/' . $f);
        }
    }

    public function test_font_files_referenced_from_css_exist_and_cover_latin_ext(): void
    {
        $css = $this->css();
        preg_match_all('/@font-face\s*\{([^}]*)\}/', $css, $faces);
        $this->assertCount(6, $faces[1], 'latin + latin-ext for Barlow 700, Barlow 800, Source Sans 3');
        $ranges = [];
        foreach ($faces[1] as $face) {
            $this->assertMatchesRegularExpression('/url\(["\']?fonts\/[a-z0-9-]+\.woff2["\']?\)/', $face);
            preg_match('/url\(["\']?(fonts\/[a-z0-9-]+\.woff2)["\']?\)/', $face, $m);
            $this->assertFileExists(self::ROOT . '/public/assets/' . $m[1]);
            $this->assertStringContainsString('unicode-range', $face);
            $this->assertStringContainsString('font-display: swap', $face);
            preg_match('/font-family:\s*"([^"]+)"/', $face, $fam);
            $ranges[$fam[1]][] = $face;
        }
        // Polish (U+0100-017F: ą ć ę ł ń ś ź ż) is covered by a latin-ext face of each family.
        foreach (['Barlow Condensed', 'Source Sans 3'] as $family) {
            $this->assertNotEmpty(array_filter($ranges[$family], fn (string $f): bool => str_contains($f, 'U+0100-02BA')), $family);
        }
        $this->assertMatchesRegularExpression('/font-family:\s*"Source Sans 3";[^}]*font-weight:\s*200 900/', $css);
    }

    public function test_dashboard_renders_layout_with_empty_state(): void
    {
        $this->loginWithCompany();
        $r = $this->get('/');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('class="side"', $r->body);
        $this->assertStringContainsString('Muster Service GmbH', $r->body);
        $this->assertStringContainsString('/assets/app.css?v=', $r->body);
        $this->assertStringContainsString('/assets/app.js?v=', $r->body);
        $this->assertStringContainsString($this->app->translator()->t('dash.welcome_title', 'de'), $r->body);
        $this->assertStringContainsString('jan@example.pl', $r->body);
        // Active nav item is highlighted.
        $this->assertMatchesRegularExpression('/<a class="nav-item is-active" href="\/"[^>]*aria-current="page"/', $r->body);
    }

    public function test_page_renders_in_all_three_languages(): void
    {
        $this->loginWithCompany();
        foreach (['de', 'pl', 'en'] as $lang) {
            $this->app->session()->set('lang', $lang);
            $r = $this->get('/');
            $this->assertSame(200, $r->status, $lang);
            $this->assertStringContainsString('<html lang="' . $lang . '"', $r->body);
            $tr = $this->app->translator();
            foreach (['nav.dashboard', 'dash.welcome_title', 'nav.company', 'login.logout'] as $key) {
                $this->assertStringContainsString(htmlspecialchars($tr->t($key, $lang), ENT_QUOTES), $r->body, "$lang $key");
            }
        }
    }

    public function test_admin_menu_hidden_for_normal_user(): void
    {
        $this->loginWithCompany();
        $r = $this->get('/');
        $this->assertSame(200, $r->status);
        $this->assertStringNotContainsString('href="/admin/companies"', $r->body);
        $this->assertStringNotContainsString($this->app->translator()->t('nav.admin', 'de'), $r->body);
    }

    public function test_admin_menu_shown_for_admin(): void
    {
        $this->loginWithCompany('admin@example.com', admin: true);
        $r = $this->get('/');
        $this->assertStringContainsString('href="/admin/companies"', $r->body);
        // Admin pages use the same shell, with the admin item active.
        $admin = $this->get('/admin/companies');
        $this->assertSame(200, $admin->status);
        $this->assertStringContainsString('class="side"', $admin->body);
        $this->assertMatchesRegularExpression('/<a class="nav-item is-active" href="\/admin\/companies"/', $admin->body);
    }

    public function test_promo_slot_hidden_by_default(): void
    {
        $this->loginWithCompany();
        $r = $this->get('/');
        $this->assertStringNotContainsString('class="promo"', $r->body);
        $this->assertStringNotContainsString($this->app->translator()->t('dash.partner_title', 'de'), $r->body);
    }

    public function test_promo_slot_shown_when_enabled(): void
    {
        $this->app = $this->makeApp(['promo_enabled' => true]);
        $this->loginWithCompany();
        $r = $this->get('/');
        $this->assertStringContainsString('class="promo"', $r->body);
        $this->assertStringContainsString($this->app->translator()->t('dash.partner_title', 'de'), $r->body);
    }

    public function test_disabled_nav_items_are_not_rendered(): void
    {
        $this->loginWithCompany('admin@example.com', admin: true);
        $body = $this->get('/')->body;
        $nav = require self::ROOT . '/config/nav.php';
        $this->assertNotEmpty($nav);
        foreach ($nav as $item) {
            if (($item['separator'] ?? false) === true) {
                continue;
            }
            $link = 'href="' . $item['path'] . '"';
            if ($item['enabled']) {
                $this->assertStringContainsString($link, $body, $item['key']);
            } else {
                $this->assertStringNotContainsString($link, $body, $item['key']);
            }
        }
    }

    public function test_nav_filter_unit(): void
    {
        $items = [
            ['key' => 'dash', 'label' => 'nav.dashboard', 'path' => '/', 'icon' => 'home', 'enabled' => true, 'admin_only' => false],
            ['separator' => true],
            ['key' => 'off', 'label' => 'nav.offers', 'path' => '/offers', 'icon' => 'offers', 'enabled' => false, 'admin_only' => false],
            ['separator' => true],
            ['key' => 'cust', 'label' => 'nav.customers', 'path' => '/customers', 'icon' => 'users', 'enabled' => true, 'admin_only' => false],
            ['separator' => true],
            ['key' => 'adm', 'label' => 'nav.admin', 'path' => '/admin/companies', 'match' => '/admin', 'icon' => 'shield', 'enabled' => true, 'admin_only' => true],
        ];
        $keys = fn (array $list): array => array_map(fn ($i) => $i['separator'] ?? false ? '|' : $i['key'] . ($i['active'] ? '*' : ''), $list);

        // Disabled item gone, double/trailing separators collapsed, admin item only for admins.
        $this->assertSame(['dash*', '|', 'cust'], $keys(Shell::navItems($items, '/', false)));
        $this->assertSame(['dash', '|', 'cust*', '|', 'adm'], $keys(Shell::navItems($items, '/customers/5/edit', true)));
        $this->assertSame(['dash', '|', 'cust', '|', 'adm*'], $keys(Shell::navItems($items, '/admin/companies/3', true)));
        // A prefix must end at a path segment: /customersX is not /customers.
        $this->assertSame(['dash', '|', 'cust'], $keys(Shell::navItems($items, '/customersX', false)));
    }

    public function test_company_box_links_to_chooser_only_with_several_companies(): void
    {
        $u = $this->loginWithCompany();
        $this->assertStringNotContainsString('href="/company/choose"', $this->get('/')->body);
        $this->addMember($u['id'], $this->createCompany('Zweite GmbH'));
        $this->assertStringContainsString('href="/company/choose"', $this->get('/')->body);
    }

    public function test_theme_and_language_switchers_are_in_the_layout(): void
    {
        $this->loginWithCompany();
        $body = $this->get('/')->body;
        $this->assertMatchesRegularExpression('/<form[^>]+method="post"[^>]+action="\/lang"/', $body);
        foreach (['de', 'pl', 'en'] as $lang) {
            $this->assertStringContainsString('name="lang" value="' . $lang . '"', $body);
        }
        $this->assertStringContainsString('name="back" value="/"', $body);
        $this->assertStringContainsString('data-theme-set="light"', $body);
        $this->assertStringContainsString('data-theme-set="dark"', $body);
    }

    public function test_app_js_is_external_and_guards_local_storage(): void
    {
        $js = file_get_contents(self::ROOT . '/public/assets/app.js');
        $this->assertIsString($js);
        $this->assertStringContainsString('localStorage', $js);
        $this->assertStringContainsString('try', $js);
        $this->assertStringContainsString('catch', $js);
    }
}
