<?php
declare(strict_types=1);

namespace App\Core;

/** Authenticated secret encryption (libsodium secretbox). Format: base64(nonce . ciphertext). */
final class Crypto
{
    private string $key;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException(
                'Encryption key must be base64 of ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes.'
            );
        }
        $this->key = $key;
    }

    public function __destruct()
    {
        sodium_memzero($this->key);
    }

    /**
     * A 32-byte subkey for one purpose (HMAC-SHA256 of the context under the app key): lets other
     * code keep a keyed hash without a second secret in the config and without using the app key itself.
     */
    public function deriveKey(string $context): string
    {
        return hash_hmac('sha256', $context, $this->key, true);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $out = base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
        sodium_memzero($plain);
        return $out;
    }

    public function decrypt(string $cipher): string
    {
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Cannot decrypt: malformed ciphertext.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $nonce,
            $this->key
        );
        if ($plain === false) {
            throw new \RuntimeException('Cannot decrypt: authentication failed.');
        }
        return $plain;
    }
}
