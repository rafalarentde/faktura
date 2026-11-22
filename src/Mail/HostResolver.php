<?php
declare(strict_types=1);

namespace App\Mail;

/** Resolves a host name to IP addresses (injectable so tests never use real DNS). */
interface HostResolver
{
    /** @return list<string> IPv4/IPv6 addresses, empty when the name does not resolve; an IP literal resolves to itself */
    public function resolve(string $host): array;
}
