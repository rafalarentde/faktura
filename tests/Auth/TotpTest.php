<?php
declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\Totp;
use OTPHP\TOTP as Otp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private const NOW = 1_700_000_010;

    private function codeAt(string $secret, int $ts): string
    {
        return Otp::createFromSecret($secret)->at($ts);
    }

    public function test_new_secret_is_base32_and_unique(): void
    {
        $t = new Totp();
        $a = $t->newSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{16,}$/', $a);
        $this->assertNotSame($a, $t->newSecret());
    }

    public function test_accepts_current_and_adjacent_steps(): void
    {
        $t = new Totp();
        $s = $t->newSecret();
        foreach ([-30, 0, 30] as $off) {
            $this->assertTrue($t->verify($s, $this->codeAt($s, self::NOW + $off), self::NOW), "offset $off");
        }
    }

    public function test_rejects_two_steps_away(): void
    {
        $t = new Totp();
        $s = $t->newSecret();
        foreach ([-60, 60] as $off) {
            $code = $this->codeAt($s, self::NOW + $off);
            // Guard against a coincidental collision with an accepted step.
            if (in_array($code, [$this->codeAt($s, self::NOW - 30), $this->codeAt($s, self::NOW), $this->codeAt($s, self::NOW + 30)], true)) {
                continue;
            }
            $this->assertFalse($t->verify($s, $code, self::NOW), "offset $off");
        }
        $this->assertTrue(true);
    }

    public function test_rfc6238_vector(): void
    {
        // RFC 6238 SHA-1 secret "12345678901234567890", T=59 -> 94287082 (8 digits) => 6 digits: 287082
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertTrue((new Totp())->verify($secret, '287082', 59));
    }

    public function test_strips_spaces_and_rejects_malformed(): void
    {
        $t = new Totp();
        $s = $t->newSecret();
        $code = $this->codeAt($s, self::NOW);
        $this->assertTrue($t->verify($s, substr($code, 0, 3) . ' ' . substr($code, 3), self::NOW));
        $this->assertFalse($t->verify($s, '', self::NOW));
        $this->assertFalse($t->verify($s, substr($code, 0, 5), self::NOW));
        $this->assertFalse($t->verify($s, $code . '0', self::NOW));
        $this->assertFalse($t->verify($s, 'abcdef', self::NOW));
        $this->assertFalse($t->verify($s, '12345é', self::NOW));
        $this->assertFalse($t->verify($s, "12345\n6", self::NOW));
    }

    public function test_verify_defaults_to_current_time(): void
    {
        $t = new Totp();
        $s = $t->newSecret();
        $this->assertTrue($t->verify($s, Otp::createFromSecret($s)->now()));
    }

    public function test_provisioning_uri_encodes_label_and_issuer(): void
    {
        $uri = (new Totp())->provisioningUri('JBSWY3DPEHPK3PXP', 'jan+faktura@example.pl');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('jan%2Bfaktura%40example.pl', $uri);
        $this->assertStringNotContainsString('jan+faktura@', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=Faktura', $uri);
        // Defaults (6 digits, 30 s, SHA-1) are omitted from the URI by otphp.
        $this->assertStringNotContainsString('digits=', $uri);
        $this->assertStringNotContainsString('period=', $uri);
        $this->assertStringNotContainsString('algorithm=', $uri);
    }

    public function test_qr_data_uri(): void
    {
        $t = new Totp();
        $uri = $t->qrDataUri($t->provisioningUri('JBSWY3DPEHPK3PXP', 'a@b.pl'));
        $this->assertStringStartsWith('data:image/', $uri);
        $this->assertStringContainsString(';base64,', $uri);
    }

    public function test_matched_step_returns_the_step_of_the_code(): void
    {
        $t = new Totp();
        $s = $t->newSecret();
        $base = intdiv(self::NOW, 30);
        foreach ([-1, 0, 1] as $d) {
            $code = $this->codeAt($s, ($base + $d) * 30);
            $this->assertSame($base + $d, $t->matchedStep($s, $code, self::NOW), "delta $d");
        }
    }

    public function test_matched_step_null_for_wrong_or_malformed_code(): void
    {
        $t = new Totp();
        $s = $t->newSecret();
        $base = intdiv(self::NOW, 30);
        $accepted = array_map(fn (int $d) => $this->codeAt($s, ($base + $d) * 30), [-1, 0, 1]);
        $wrong = '000000';
        while (in_array($wrong, $accepted, true)) {
            $wrong = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        }
        $this->assertNull($t->matchedStep($s, $wrong, self::NOW));
        $this->assertNull($t->matchedStep($s, 'abcdef', self::NOW));
        $this->assertNull($t->matchedStep($s, '', self::NOW));
    }
}
