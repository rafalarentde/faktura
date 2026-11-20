<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Mail\HostResolver;

/** Name => addresses map; IP literals resolve to themselves; unknown names do not resolve. No real DNS. */
final class FakeResolver implements HostResolver
{
    public int $calls = 0;

    /** @param array<string, list<string>> $map */
    public function __construct(private array $map = [])
    {
    }

    public static function publicHosts(): self
    {
        return new self(['smtp.example.com' => ['93.184.216.34'], 'smtp.firma.example' => ['93.184.216.35'], 'smtp.v6.example' => ['2606:2800:220:1::1']]);
    }

    public function resolve(string $host): array
    {
        $this->calls++;
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return [$bare];
        }
        return $this->map[$host] ?? [];
    }
}
