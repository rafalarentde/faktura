<?php
declare(strict_types=1);

namespace App\Mail;

/** System resolver: IP literals as they are, names through A (gethostbynamel) and AAAA records. */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return [$bare];
        }
        $out = [];
        $v4 = @gethostbynamel($host);
        foreach (is_array($v4) ? $v4 : [] as $ip) {
            $out[] = $ip;
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($v6) ? $v6 : [] as $rec) {
            if (isset($rec['ipv6']) && is_string($rec['ipv6'])) {
                $out[] = $rec['ipv6'];
            }
        }
        return array_values(array_unique($out));
    }
}
