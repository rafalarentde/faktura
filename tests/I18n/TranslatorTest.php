<?php
declare(strict_types=1);

namespace Tests\I18n;

use App\I18n\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    private const LANGS = ['de', 'pl', 'en'];

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/faktura-i18n-' . bin2hex(random_bytes(6));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*.php') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmp);
    }

    private function real(): Translator
    {
        return new Translator(dirname(__DIR__, 2) . '/lang');
    }

    /** @param array<string, array<string,string>> $files */
    private function fake(array $files): Translator
    {
        foreach ($files as $lang => $map) {
            file_put_contents("{$this->tmp}/{$lang}.php", '<?php return ' . var_export($map, true) . ';');
        }
        return new Translator($this->tmp);
    }

    public function test_parity(): void
    {
        $tr = $this->real();
        $de = $tr->keys('de');
        $this->assertNotEmpty($de);
        foreach (['pl', 'en'] as $lang) {
            $keys = $tr->keys($lang);
            $this->assertSame(
                [],
                array_merge(
                    array_map(fn ($k) => "missing in $lang: $k", array_values(array_diff($de, $keys))),
                    array_map(fn ($k) => "extra in $lang: $k", array_values(array_diff($keys, $de))),
                ),
                "Key mismatch between de and $lang"
            );
        }
    }

    public function test_values_not_empty_and_placeholders_match_german(): void
    {
        $tr = $this->real();
        $placeholders = static function (string $s): array {
            preg_match_all('/\{(\w+)\}/', $s, $m);
            $p = array_unique($m[1]);
            sort($p);
            return $p;
        };
        foreach (self::LANGS as $lang) {
            foreach ($tr->keys($lang) as $key) {
                $text = $tr->t($key, $lang);
                $this->assertNotSame('', trim($text), "Empty value for $lang/$key");
                $this->assertSame(
                    $placeholders($tr->t($key, 'de')),
                    $placeholders($text),
                    "Placeholder mismatch for $lang/$key"
                );
            }
        }
    }

    public function test_variable_substitution(): void
    {
        $tr = $this->fake(['de' => ['a' => 'Hallo {name}, {n} Stück {name}!']]);
        $this->assertSame('Hallo Anna, 3 Stück Anna!', $tr->t('a', 'de', ['name' => 'Anna', 'n' => 3]));
        $this->assertSame('Hallo Anna, {n} Stück Anna!', $tr->t('a', 'de', ['name' => 'Anna']));
        $this->assertSame('Hallo {name}, {n} Stück {name}!', $tr->t('a', 'de'));
        $this->assertSame('Hallo <b>, {n} Stück <b>!', $tr->t('a', 'de', ['name' => '<b>']));
    }

    public function test_fallback_to_german_then_key(): void
    {
        $tr = $this->fake([
            'de' => ['only.de' => 'Nur deutsch {x}', 'both' => 'Beide'],
            'en' => ['both' => 'Both'],
        ]);
        $this->assertSame('Both', $tr->t('both', 'en'));
        $this->assertSame('Nur deutsch 1', $tr->t('only.de', 'en', ['x' => 1]));
        $this->assertSame('missing.key', $tr->t('missing.key', 'en'));
        // pl file does not exist at all
        $this->assertSame('Beide', $tr->t('both', 'pl'));
    }

    public function test_unsupported_language_falls_back_to_german(): void
    {
        $tr = $this->fake(['de' => ['k' => 'Deutsch'], 'en' => ['k' => 'English']]);
        $this->assertSame('Deutsch', $tr->t('k', 'fr'));
        $this->assertSame('Deutsch', $tr->t('k', '../en'));
        $this->assertSame('Deutsch', $tr->t('k', ''));
        $this->assertSame($tr->keys('de'), $tr->keys('xx'));
    }

    public function test_polish_diacritics_preserved(): void
    {
        $tr = $this->real();
        $this->assertSame('Hasło', $tr->t('login.password', 'pl'));
        $this->assertSame('Opłacona', $tr->t('status.paid', 'pl'));
        $this->assertSame('Rechnung', $tr->t('invoice.title', 'de'));
        $all = implode('', array_map(fn ($k) => $tr->t($k, 'pl'), $tr->keys('pl')));
        foreach (['ą', 'ć', 'ę', 'ł', 'ń', 'ó', 'ś', 'ż'] as $ch) {
            $this->assertStringContainsString($ch, $all, "Polish text lacks '$ch'");
        }
        $de = implode('', array_map(fn ($k) => $tr->t($k, 'de'), $tr->keys('de')));
        foreach (['ä', 'ö', 'ü', 'ß'] as $ch) {
            $this->assertStringContainsString($ch, $de, "German text lacks '$ch'");
        }
    }

    public function test_reverse_charge_note_present_in_all_languages(): void
    {
        $tr = $this->real();
        $this->assertStringContainsString('Art. 196 MwStSystRL', $tr->t('invoice.reverse_charge_note', 'de'));
        $this->assertStringContainsString('art. 196 dyrektywy VAT', $tr->t('invoice.reverse_charge_note', 'pl'));
        $this->assertStringContainsString('Art. 196 VAT Directive', $tr->t('invoice.reverse_charge_note', 'en'));
    }

    public function test_keys_are_dotted_lowercase_snake_case(): void
    {
        foreach ($this->real()->keys('de') as $key) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $key);
        }
    }
}
