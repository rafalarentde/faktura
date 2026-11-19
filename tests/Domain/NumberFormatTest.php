<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\InvalidNumberFormat;
use App\Domain\NumberFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumberFormatTest extends TestCase
{
    private function d(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Berlin'));
    }

    public function test_default_invoice_example(): void
    {
        $this->assertSame('RE-2026-042', NumberFormat::parse('RE-{YYYY}-{NNN}')->render($this->d('2026-11-18'), 42));
    }

    public function test_short_year_month_and_wide_counter(): void
    {
        $this->assertSame('FV/26/11/0042', NumberFormat::parse('FV/{YY}/{MM}/{NNNN}')->render($this->d('2026-11-18'), 42));
    }

    public function test_single_n_does_not_truncate(): void
    {
        $f = NumberFormat::parse('{N}');
        $this->assertSame('1234', $f->render($this->d('2026-11-18'), 1234));
        $this->assertSame('5', $f->render($this->d('2026-11-18'), 5));
    }

    public function test_day_token_and_padding(): void
    {
        $this->assertSame('AN-20260105-07', NumberFormat::parse('AN-{YYYY}{MM}{DD}-{NN}')->render($this->d('2026-01-05'), 7));
    }

    public function test_literal_text_with_digits_dots_and_spaces_survives(): void
    {
        $f = NumberFormat::parse('No. 2026.A #{NNN} / x_y');
        $this->assertSame('No. 2026.A #003 / x_y', $f->render($this->d('2026-11-18'), 3));
    }

    public function test_date_tokens_follow_the_timezone_of_the_given_date(): void
    {
        $berlin = $this->d('2026-12-31 23:30');
        $this->assertSame('2026', $berlin->format('Y'));
        $this->assertSame('X-2026-1', NumberFormat::parse('X-{YYYY}-{N}')->render($berlin, 1));
        $utc = $berlin->setTimezone(new \DateTimeZone('UTC')); // still Dec 31 22:30 UTC
        $this->assertSame('X-2026-1', NumberFormat::parse('X-{YYYY}-{N}')->render($utc, 1));
        $ny = new \DateTimeImmutable('2027-01-01 00:30', new \DateTimeZone('Europe/Berlin'));
        $this->assertSame('X-2027-1', NumberFormat::parse('X-{YYYY}-{N}')->render($ny, 1));
    }

    public function test_has_year_and_counter_width(): void
    {
        $this->assertTrue(NumberFormat::parse('A{YYYY}{N}')->hasYear());
        $this->assertTrue(NumberFormat::parse('A{YY}{N}')->hasYear());
        $this->assertFalse(NumberFormat::parse('A{MM}{DD}{NNN}')->hasYear());
        $this->assertSame(4, NumberFormat::parse('A{NNNN}')->counterWidth());
        $this->assertSame('A{NNNN}', NumberFormat::parse('A{NNNN}')->pattern());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalid(): array
    {
        return [
            'no counter' => ['FAKTURA', InvalidNumberFormat::NO_COUNTER],
            'only date tokens' => ['{YYYY}-{MM}', InvalidNumberFormat::NO_COUNTER],
            'two counters' => ['{NNN}-{NN}', InvalidNumberFormat::MULTIPLE_COUNTERS],
            'lower-case token' => ['RE-{yyyy}-{NNN}', InvalidNumberFormat::UNKNOWN_TOKEN],
            'unknown token' => ['RE-{ABC}-{NNN}', InvalidNumberFormat::UNKNOWN_TOKEN],
            'empty token' => ['RE-{}-{NNN}', InvalidNumberFormat::UNKNOWN_TOKEN],
            'eleven Ns' => ['{NNNNNNNNNNN}', InvalidNumberFormat::UNKNOWN_TOKEN],
            'mixed counter' => ['{NNx}', InvalidNumberFormat::UNKNOWN_TOKEN],
            'open brace' => ['RE-{NNN', InvalidNumberFormat::UNBALANCED],
            'N open' => ['{N', InvalidNumberFormat::UNBALANCED],
            'stray close' => ['RE-}{NNN}', InvalidNumberFormat::UNBALANCED],
            'nested' => ['RE-{{NNN}}', InvalidNumberFormat::UNBALANCED],
            'empty' => ['', InvalidNumberFormat::TOO_SHORT],
            'too long' => [str_repeat('A', 55) . '{NNNNN}', InvalidNumberFormat::TOO_LONG],
            'control char' => ["RE\n-{NNN}", InvalidNumberFormat::INVALID_CHAR],
            'nul' => ["RE\0{NNN}", InvalidNumberFormat::INVALID_CHAR],
            'invalid utf8' => ["RE\xff{NNN}", InvalidNumberFormat::INVALID_CHAR],
            'rendered too long' => [str_repeat('A', 33) . '{N}', InvalidNumberFormat::RENDERED_TOO_LONG],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_patterns_throw(string $pattern, string $reason): void
    {
        try {
            NumberFormat::parse($pattern);
            $this->fail('Expected InvalidNumberFormat');
        } catch (InvalidNumberFormat $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertSame('settings.err_num_' . $reason, $e->translationKey());
        }
    }

    public function test_sixty_character_pattern_is_the_upper_limit(): void
    {
        $this->expectException(InvalidNumberFormat::class);
        NumberFormat::parse(str_repeat('A', 56) . '{NNN}'); // 61 characters
    }

    public function test_length_limits_on_the_rendered_number(): void
    {
        // 30 literals + a 10-wide counter = exactly 40 characters: fits.
        $f = NumberFormat::parse(str_repeat('A', 30) . '{NNNNNNNNNN}');
        $this->assertSame(40, mb_strlen($f->render($this->d('2026-01-01'), NumberFormat::MAX_COUNTER)));
        // 31 literals + {NNNNNNNNNN} = 41: too long.
        $this->expectException(InvalidNumberFormat::class);
        NumberFormat::parse(str_repeat('A', 31) . '{NNNNNNNNNN}');
    }

    public function test_multibyte_literals_count_as_characters(): void
    {
        $f = NumberFormat::parse('Rechnung Nr. äöü-{NNN}');
        $this->assertSame('Rechnung Nr. äöü-001', $f->render($this->d('2026-01-01'), 1));
    }

    public function test_negative_counter_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NumberFormat::parse('{N}')->render($this->d('2026-01-01'), -1);
    }
}
