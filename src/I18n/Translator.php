<?php
declare(strict_types=1);

namespace App\I18n;

/** Looks up UI/PDF texts from flat lang/{de,pl,en}.php arrays; German is the fallback. */
final class Translator
{
    public const SUPPORTED = ['de', 'pl', 'en'];
    public const FALLBACK = 'de';

    /** @var array<string, array<string, string>> */
    private array $cache = [];

    public function __construct(private readonly string $langDir)
    {
    }

    /** @param array<string, scalar> $vars */
    public function t(string $key, string $lang, array $vars = []): string
    {
        $lang = self::normalize($lang);
        $text = $this->load($lang)[$key] ?? $this->load(self::FALLBACK)[$key] ?? $key;
        if ($vars === []) {
            return $text;
        }
        $map = [];
        foreach ($vars as $name => $value) {
            $map['{' . $name . '}'] = (string) $value;
        }
        return strtr($text, $map);
    }

    /** @return list<string> */
    public function keys(string $lang): array
    {
        return array_keys($this->load(self::normalize($lang)));
    }

    private static function normalize(string $lang): string
    {
        return in_array($lang, self::SUPPORTED, true) ? $lang : self::FALLBACK;
    }

    /** @return array<string, string> */
    private function load(string $lang): array
    {
        if (!isset($this->cache[$lang])) {
            $file = rtrim($this->langDir, '/') . "/$lang.php";
            $data = is_file($file) ? require $file : [];
            $this->cache[$lang] = is_array($data) ? $data : [];
        }
        return $this->cache[$lang];
    }
}
