<?php
declare(strict_types=1);

namespace Tests\I18n;

use PHPUnit\Framework\TestCase;

/** lang files: no key defined twice (PHP would silently keep the last), no key nobody can reach, no hard-coded names/numbers. */
final class LangHygieneTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    /** Keys are also built at run time ('prefix.' . $x); these prefixes are looked up dynamically in src/ and templates/. */
    private const DYNAMIC_PREFIXES = [
        'dunning.level_', 'settings.err_num_', 'unit.', 'invoice.type_', 'pdf.dunning_title_', 'pdf.dunning_text_', 'lang.',
        'invoice.status_', 'mail.fail_', 'offer.status_', 'settings.doctype_', 'nav.plan_', 'mail.subject_', 'dash.tab_', 'item.type_',
    ];
    /** Looked up through a loop variable in a template (company.twig: 'settings.' ~ f). */
    private const LOOP_KEYS = ['settings.intro_text', 'settings.outro_text', 'settings.footer_text'];

    public function test_no_key_is_defined_twice_in_a_language_file(): void
    {
        foreach (['de', 'pl', 'en'] as $lang) {
            preg_match_all("/^\\s*'([^']+)'\\s*=>/m", (string) file_get_contents(self::ROOT . "/lang/$lang.php"), $m);
            $counts = array_count_values($m[1]);
            $this->assertSame([], array_keys(array_filter($counts, fn (int $n): bool => $n > 1)), "duplicate keys in $lang.php");
        }
    }

    public function test_every_key_is_used_somewhere(): void
    {
        $source = '';
        foreach (['src', 'templates', 'config', 'bin', 'public'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && preg_match('/\.(php|twig)\z/', $f->getFilename()) === 1) {
                    $source .= file_get_contents($f->getPathname()) . "\n";
                }
            }
        }
        preg_match_all("/^\\s*'([^']+)'\\s*=>/m", (string) file_get_contents(self::ROOT . '/lang/de.php'), $m);
        $orphans = [];
        foreach (array_unique($m[1]) as $key) {
            if (str_contains($source, "'$key'") || str_contains($source, "\"$key\"") || in_array($key, self::LOOP_KEYS, true)) {
                continue;
            }
            // Templates compose 'invoice'/'offer' . '.suffix' from a variable (t(kind ~ '.edit_title')).
            if (str_contains($source, "'." . substr($key, (int) strpos($key, '.') + 1) . "'")) {
                continue;
            }
            foreach (self::DYNAMIC_PREFIXES as $p) {
                if (str_starts_with($key, $p)) {
                    continue 2;
                }
            }
            $orphans[] = $key;
        }
        $this->assertSame([], $orphans, 'lang keys that no code references (delete them, or add the dynamic prefix)');
    }

    public function test_no_hard_coded_brand_name_or_surcharge_in_the_texts(): void
    {
        foreach (['de', 'pl', 'en'] as $lang) {
            $texts = (array) require self::ROOT . "/lang/$lang.php";
            $this->assertStringContainsString('{name}', (string) $texts['nav.byline'], "nav.byline ($lang) takes the brand name as a parameter");
            $this->assertStringNotContainsString('Behapowiec', (string) $texts['nav.byline']);
            $this->assertStringContainsString('{points}', (string) $texts['dunning.charge_interest_hint'], "$lang: the B2B surcharge comes from the settings");
            $this->assertDoesNotMatchRegularExpression('/\b9\b/', (string) $texts['dunning.charge_interest_hint']);
        }
    }
}
