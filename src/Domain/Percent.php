<?php
declare(strict_types=1);

namespace App\Domain;

/** Percentages typed with up to 2 decimals <-> basis points (1 % = 100 bp), string arithmetic only. */
final class Percent
{
    /**
     * "1,27" (de/pl) or "1.27" (en) -> 127. An optional trailing % is allowed. At most 2 decimals
     * ("1,234" is refused, never read as 1234), no sign, no group separators.
     *
     * @throws \InvalidArgumentException
     */
    public static function parseBp(string $input): int
    {
        $s = trim($input, " \t\n\r\u{00A0}");
        $s = rtrim(substr($s, -1) === '%' ? substr($s, 0, -1) : $s);
        if (preg_match('/\A([0-9]{1,3})(?:[.,]([0-9]{1,2}))?\z/', $s, $m) !== 1) {
            throw new \InvalidArgumentException('Not a valid percentage.');
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** 127 -> "1,27" (de/pl) / "1.27" (en); whole numbers without decimals (900 -> "9"), 150 -> "1,5". */
    public static function format(int $bp, string $lang): string
    {
        $int = intdiv($bp, 100);
        $frac = rtrim(str_pad((string) ($bp % 100), 2, '0', STR_PAD_LEFT), '0');
        return $frac === '' ? (string) $int : $int . ($lang === 'en' ? '.' : ',') . $frac;
    }
}
