<?php
declare(strict_types=1);

namespace App\Mail;

/**
 * Where a company's SMTP settings may point (SSRF guard). The owner of any tenant controls
 * smtp_host and smtp_port, so without this the mail features would be a port scanner against
 * loopback, the LAN and cloud metadata addresses.
 *
 * - Ports: only 25, 465, 587, 2525.
 * - Host: must resolve (resolver is injectable) and EVERY address must be public; loopback,
 *   private, link-local, unspecified, reserved, carrier-grade NAT and documentation ranges are
 *   refused, IPv4 embedded in IPv6 (::ffff:a.b.c.d, ::a.b.c.d, 6to4, NAT64) is unwrapped and
 *   re-checked, Teredo is refused.
 * - connectAddress() returns the address to connect to (first IPv4, else first IPv6), so the
 *   address that was checked is the address that is used (no DNS rebinding between check and
 *   connect); the caller keeps verifying the certificate against the configured host name.
 * - $allowPrivate (config mail_allow_private_hosts, default false) skips only the address
 *   check, for self-hosters with a LAN relay; the port list still applies.
 */
final class SmtpTargetPolicy
{
    public const PORTS = [25, 465, 587, 2525];

    private const V4_BLOCKS = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12],
        ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
        ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];

    private HostResolver $resolver;

    public function __construct(?HostResolver $resolver = null, private bool $allowPrivate = false)
    {
        $this->resolver = $resolver ?? new DnsHostResolver();
    }

    public static function portAllowed(int $port): bool
    {
        return in_array($port, self::PORTS, true);
    }

    /** The address to connect to, or null when the host is refused (unresolvable or not public). */
    public function connectAddress(string $host): ?string
    {
        $host = trim($host);
        $lower = strtolower(rtrim($host, '.'));
        if ($host === '') {
            return null;
        }
        if ($this->allowPrivate) {
            return $host;
        }
        if ($lower === 'localhost' || str_ends_with($lower, '.localhost')) {
            return null;
        }
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            return null;
        }
        foreach ($addresses as $ip) {
            if (!self::isPublicIp($ip)) {
                return null;
            }
        }
        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ip;
            }
        }
        return '[' . $addresses[0] . ']';
    }

    public static function isPublicIp(string $ip): bool
    {
        $ip = trim($ip, '[]');
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 4) {
            return self::publicV4($bin);
        }
        // ::, ::1, fc00::/7, fe80::/10, multicast ff00::/8
        $b0 = ord($bin[0]);
        $b1 = ord($bin[1]);
        if ($bin === str_repeat("\0", 16) || $bin === str_repeat("\0", 15) . "\1" || ($b0 & 0xFE) === 0xFC || ($b0 === 0xFE && ($b1 & 0xC0) === 0x80) || $b0 === 0xFF) {
            return false;
        }
        $head = substr($bin, 0, 12);
        // ::ffff:a.b.c.d (mapped), ::a.b.c.d (compatible), 64:ff9b::/96 (NAT64): check the embedded IPv4
        if ($head === str_repeat("\0", 10) . "\xFF\xFF" || $head === str_repeat("\0", 12) || $head === "\x00\x64\xFF\x9B" . str_repeat("\0", 8)) {
            return self::publicV4(substr($bin, 12, 4));
        }
        // 2002::/16 (6to4): IPv4 in bytes 2-5; 2001::/32 (Teredo) is refused outright
        if ($b0 === 0x20 && $b1 === 0x02) {
            return self::publicV4(substr($bin, 2, 4));
        }
        if (substr($bin, 0, 4) === "\x20\x01\x00\x00") {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function publicV4(string $bin): bool
    {
        $ip = inet_ntop($bin);
        if ($ip === false || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $n = (int) sprintf('%u', ip2long($ip));
        foreach (self::V4_BLOCKS as [$base, $bits]) {
            $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
            if (($n & $mask) === ((int) sprintf('%u', ip2long($base)) & $mask)) {
                return false;
            }
        }
        return true;
    }
}
