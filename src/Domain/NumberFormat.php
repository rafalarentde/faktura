<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * A document-number pattern such as `RE-{YYYY}-{NNN}`.
 *
 * Tokens: {YYYY} {YY} {MM} {DD} and exactly one counter {N}..{NNNNNNNNNN} (1-10 Ns; the number of
 * Ns is the minimum width, zero-padded; wider numbers are never truncated). Everything outside
 * braces is literal text (any character except control/format characters). No leading or trailing whitespace. Pattern length 1-60
 * characters; the longest possible rendering (counter at 10 digits) must fit
 * MAX_RENDERED_LENGTH = the documents.number column.
 *
 * Date tokens use the timezone of the DateTimeImmutable passed to render(): callers must pass the
 * Europe/Berlin business date (the document date), never a UTC timestamp.
 */
final class NumberFormat
{
    public const MAX_PATTERN_LENGTH = 60;
    public const MAX_RENDERED_LENGTH = 40;
    public const MAX_COUNTER_DIGITS = 10;
    /** Largest counter that still renders within MAX_COUNTER_DIGITS. */
    public const MAX_COUNTER = 9_999_999_999;

    /** @param list<array{0: string, 1: string|int}> $parts ['lit', text] | ['tok', YYYY|YY|MM|DD] | ['cnt', width] */
    private function __construct(private string $pattern, private array $parts, private bool $hasYear, private int $width)
    {
    }

    /** @throws InvalidNumberFormat */
    public static function parse(string $pattern): self
    {
        // \p{C}: control, format (zero-width, bidi overrides), surrogate, private-use, unassigned.
        if (!mb_check_encoding($pattern, 'UTF-8') || preg_match('/\p{C}/u', $pattern) === 1) {
            throw new InvalidNumberFormat(InvalidNumberFormat::INVALID_CHAR);
        }
        if (preg_match('/\A[\s\p{Z}]|[\s\p{Z}]\z/u', $pattern) === 1) {
            throw new InvalidNumberFormat(InvalidNumberFormat::OUTER_SPACE);
        }
        $len = mb_strlen($pattern, 'UTF-8');
        if ($len < 1) {
            throw new InvalidNumberFormat(InvalidNumberFormat::TOO_SHORT);
        }
        if ($len > self::MAX_PATTERN_LENGTH) {
            throw new InvalidNumberFormat(InvalidNumberFormat::TOO_LONG);
        }

        $parts = [];
        $hasYear = false;
        $width = 0;
        $counters = 0;
        $literal = '';
        $chars = mb_str_split($pattern, 1, 'UTF-8');
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $c = $chars[$i];
            if ($c === '}') {
                throw new InvalidNumberFormat(InvalidNumberFormat::UNBALANCED);
            }
            if ($c !== '{') {
                $literal .= $c;
                continue;
            }
            $token = '';
            for ($i++; ; $i++) {
                if ($i >= $n || $chars[$i] === '{') {
                    throw new InvalidNumberFormat(InvalidNumberFormat::UNBALANCED);
                }
                if ($chars[$i] === '}') {
                    break;
                }
                $token .= $chars[$i];
            }
            if ($literal !== '') {
                $parts[] = ['lit', $literal];
                $literal = '';
            }
            if (in_array($token, ['YYYY', 'YY', 'MM', 'DD'], true)) {
                $hasYear = $hasYear || $token === 'YYYY' || $token === 'YY';
                $parts[] = ['tok', $token];
            } elseif (preg_match('/\AN{1,' . self::MAX_COUNTER_DIGITS . '}\z/', $token) === 1) {
                $counters++;
                $width = strlen($token);
                $parts[] = ['cnt', $width];
            } else {
                throw new InvalidNumberFormat(InvalidNumberFormat::UNKNOWN_TOKEN);
            }
        }
        if ($literal !== '') {
            $parts[] = ['lit', $literal];
        }
        if ($counters === 0) {
            throw new InvalidNumberFormat(InvalidNumberFormat::NO_COUNTER);
        }
        if ($counters > 1) {
            throw new InvalidNumberFormat(InvalidNumberFormat::MULTIPLE_COUNTERS);
        }

        $format = new self($pattern, $parts, $hasYear, $width);
        $longest = $format->render(new \DateTimeImmutable('2000-12-31'), self::MAX_COUNTER);
        if (mb_strlen($longest, 'UTF-8') > self::MAX_RENDERED_LENGTH) {
            throw new InvalidNumberFormat(InvalidNumberFormat::RENDERED_TOO_LONG);
        }
        return $format;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    /** True when {YYYY} or {YY} is present. */
    public function hasYear(): bool
    {
        return $this->hasYear;
    }

    /** Minimum digit width of the counter. */
    public function counterWidth(): int
    {
        return $this->width;
    }

    /** @throws \InvalidArgumentException for a counter below 0 */
    public function render(\DateTimeImmutable $d, int $counter): string
    {
        if ($counter < 0) {
            throw new \InvalidArgumentException('Counter must not be negative.');
        }
        $out = '';
        foreach ($this->parts as [$kind, $value]) {
            $out .= match ($kind) {
                'lit' => $value,
                'cnt' => str_pad((string) $counter, (int) $value, '0', STR_PAD_LEFT),
                default => match ($value) {
                    'YYYY' => $d->format('Y'),
                    'YY' => $d->format('y'),
                    'MM' => $d->format('m'),
                    default => $d->format('d'),
                },
            };
        }
        return $out;
    }
}
