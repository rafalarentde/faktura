<?php
declare(strict_types=1);

namespace App\Auth;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use OTPHP\TOTP as OtpTotp;

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s) as supported by authenticator apps. */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;

    public function newSecret(): string
    {
        return OtpTotp::generate()->getSecret();
    }

    public function provisioningUri(string $secret, string $email, string $issuer = 'Faktura'): string
    {
        $otp = OtpTotp::createFromSecret($secret);
        $otp->setPeriod(self::PERIOD);
        $otp->setDigits(self::DIGITS);
        $otp->setDigest('sha1');
        $otp->setIssuer($issuer);
        $otp->setLabel($email);
        return $otp->getProvisioningUri();
    }

    /** SVG data URI of the QR code; needs no image extension. */
    public function qrDataUri(string $uri): string
    {
        return (new Builder(writer: new SvgWriter(), data: $uri, size: 240, margin: 8))
            ->build()
            ->getDataUri();
    }

    /** Accepts the current 30 s step and its direct neighbours. */
    public function verify(string $secret, string $code, ?int $now = null): bool
    {
        return $this->matchedStep($secret, $code, $now) !== null;
    }

    /**
     * Returns the 30 s step number (unix time / 30) the code belongs to, checking the
     * current step and its direct neighbours, or null if the code matches none of them.
     * Callers store the step to reject replays (accept only steps greater than the last).
     */
    public function matchedStep(string $secret, string $code, ?int $now = null): ?int
    {
        $code = str_replace(' ', '', $code);
        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            return null;
        }
        $base = intdiv($now ?? time(), self::PERIOD);
        $otp = OtpTotp::createFromSecret($secret);
        $match = null;
        foreach ([-1, 0, 1] as $delta) {
            // No early exit: every step is evaluated and compared in constant time.
            $ok = hash_equals($otp->at(($base + $delta) * self::PERIOD), $code);
            if ($ok && $match === null) {
                $match = $base + $delta;
            }
        }
        return $match;
    }
}
