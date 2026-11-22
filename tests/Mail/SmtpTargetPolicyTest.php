<?php
declare(strict_types=1);

namespace Tests\Mail;

use App\Mail\SmtpTargetPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** isPublicIp(): IPv4 by block list; IPv6 only inside 2000::/3 minus the special-purpose ranges. */
final class SmtpTargetPolicyTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        $ips = [
            // IPv4
            '127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.0.1', '169.254.169.254', '0.0.0.0', '100.64.0.1', '224.0.0.1', '255.255.255.255',
            // IPv6 outside 2000::/3
            '::', '::1', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1', 'fec0::1', '100::1', '4000::1', '8000::1', 'c000::1', 'e000::1',
            // IPv4-mapped / compatible / SIIT / NAT64 (all outside 2000::/3)
            '::ffff:127.0.0.1', '::ffff:93.184.216.34', '::ffff:7f00:1', '::127.0.0.1', '::93.184.216.34', '::ffff:0:127.0.0.1', '::ffff:0:93.184.216.34',
            '64:ff9b::7f00:1', '64:ff9b::5db8:d822', '64:ff9b:1::1', '64:ff9b:1:ffff::1',
            // special ranges INSIDE 2000::/3
            '3fff::1', '3fff:fff:ffff::1', '2001::1', '2001:0:4136:e378:8000:63bf:3fff:fdd2', '2001:1::1', '2001:1ff:ffff::1', '2001:db8::1',
            '2002::1', '2002:7f00:1::1', '2002:a9fe:a9fe::', '2002:5db8:d822::1',
            // not an address
            'not-an-ip', '', '1.2.3', '2606:2800:220:1::g',
        ];
        $out = [];
        foreach ($ips as $ip) {
            $out[$ip === '' ? '(empty)' : $ip] = [$ip];
        }
        return $out;
    }

    #[DataProvider('refused')]
    public function test_refused(string $ip): void
    {
        $this->assertFalse(SmtpTargetPolicy::isPublicIp($ip));
        $this->assertFalse(SmtpTargetPolicy::isPublicIp('[' . $ip . ']'));
    }

    /** @return array<string, array{string}> */
    public static function accepted(): array
    {
        $out = [];
        foreach (['93.184.216.34', '8.8.8.8', '1.1.1.1', '2606:2800:220:1::1', '2a00:1450:4001::1', '2001:4860:4860::8888', '2001:200::1', '2001:600::1', '2400:cb00::1', '3ffe::1', '3ffe:ffff::1', '2a01:4f8::1'] as $ip) {
            $out[$ip] = [$ip];
        }
        return $out;
    }

    #[DataProvider('accepted')]
    public function test_public_addresses_are_accepted(string $ip): void
    {
        $this->assertTrue(SmtpTargetPolicy::isPublicIp($ip));
    }

    public function test_the_edges_of_the_special_ranges(): void
    {
        $this->assertFalse(SmtpTargetPolicy::isPublicIp('3fff:0fff:ffff:ffff:ffff:ffff:ffff:ffff'), 'last address of 3fff::/20');
        $this->assertTrue(SmtpTargetPolicy::isPublicIp('3fff:1000::1'), 'first address after 3fff::/20');
        $this->assertFalse(SmtpTargetPolicy::isPublicIp('2001:1ff:ffff:ffff:ffff:ffff:ffff:ffff'), 'last address of 2001::/23');
        $this->assertTrue(SmtpTargetPolicy::isPublicIp('2001:200::1'), 'first address after 2001::/23');
        $this->assertFalse(SmtpTargetPolicy::isPublicIp('2002:ffff:ffff:ffff:ffff:ffff:ffff:ffff'));
        $this->assertTrue(SmtpTargetPolicy::isPublicIp('2003::1'));
        $this->assertTrue(SmtpTargetPolicy::isPublicIp('2000::1'), 'start of 2000::/3');
        $this->assertTrue(SmtpTargetPolicy::isPublicIp('3ffe::1'), 'end of 2000::/3');
        $this->assertFalse(SmtpTargetPolicy::isPublicIp('4000::1'), 'outside 2000::/3');
        $this->assertFalse(SmtpTargetPolicy::isPublicIp('1fff:ffff::1'), 'just below 2000::/3');
    }
}
