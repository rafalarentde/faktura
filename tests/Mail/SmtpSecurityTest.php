<?php
declare(strict_types=1);

namespace Tests\Mail;

use App\Mail\SmtpSecurity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmtpSecurityTest extends TestCase
{
    #[DataProvider('ports')]
    public function test_secure_mode_by_port(int $port, string $expected): void
    {
        $this->assertSame($expected, SmtpSecurity::smtpSecure($port));
    }

    /** @return array<string, array{int, string}> */
    public static function ports(): array
    {
        return [
            '465 implicit TLS' => [465, 'tls'],
            '587 STARTTLS' => [587, 'starttls'],
            '25 STARTTLS, never plaintext' => [25, 'starttls'],
            '2525 STARTTLS' => [2525, 'starttls'],
            '1 STARTTLS' => [1, 'starttls'],
        ];
    }
}
