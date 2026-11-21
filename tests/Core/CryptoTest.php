<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Crypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    private function key(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function test_roundtrip_returns_original(): void
    {
        $c = new Crypto($this->key());
        foreach (['JBSWY3DPEHPK3PXP', 'Zażółć gęślą jaźń', 'Größe ß'] as $plain) {
            $this->assertSame($plain, $c->decrypt($c->encrypt($plain)));
        }
    }

    public function test_empty_string_roundtrip(): void
    {
        $c = new Crypto($this->key());
        $this->assertSame('', $c->decrypt($c->encrypt('')));
    }

    public function test_two_encryptions_differ(): void
    {
        $c = new Crypto($this->key());
        $this->assertNotSame($c->encrypt('secret'), $c->encrypt('secret'));
    }

    public function test_tampered_cipher_throws(): void
    {
        $c = new Crypto($this->key());
        $raw = base64_decode($c->encrypt('secret'), true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";
        $this->expectException(\RuntimeException::class);
        $c->decrypt(base64_encode($raw));
    }

    public function test_wrong_key_throws(): void
    {
        $cipher = (new Crypto($this->key()))->encrypt('secret');
        $this->expectException(\RuntimeException::class);
        (new Crypto($this->key()))->decrypt($cipher);
    }

    #[DataProvider('badCiphers')]
    public function test_malformed_cipher_throws(string $bad): void
    {
        $this->expectException(\RuntimeException::class);
        (new Crypto($this->key()))->decrypt($bad);
    }

    public static function badCiphers(): array
    {
        return [
            'not base64' => ['!!!not base64!!!'],
            'empty' => [''],
            'shorter than nonce' => [base64_encode('short')],
            'nonce only' => [base64_encode(str_repeat('a', SODIUM_CRYPTO_SECRETBOX_NONCEBYTES))],
        ];
    }

    #[DataProvider('badKeys')]
    public function test_invalid_key_rejected(string $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Crypto($bad);
    }

    public function test_derive_key_is_deterministic_per_context_and_key(): void
    {
        $a = new Crypto(base64_encode(str_repeat('a', 32)));
        $b = new Crypto(base64_encode(str_repeat('b', 32)));
        $this->assertSame(32, strlen($a->deriveKey('x')));
        $this->assertSame($a->deriveKey('x'), $a->deriveKey('x'));
        $this->assertNotSame($a->deriveKey('x'), $a->deriveKey('y'));
        $this->assertNotSame($a->deriveKey('x'), $b->deriveKey('x'));
        $this->assertNotSame(str_repeat('a', 32), $a->deriveKey('x'));
    }

    public static function badKeys(): array
    {
        return [
            'not base64' => ['***'],
            'empty' => [''],
            'too short' => [base64_encode(random_bytes(16))],
            'too long' => [base64_encode(random_bytes(33))],
        ];
    }
}
