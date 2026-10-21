<?php

namespace App\Services\Domains;

interface DnsLookup
{
    /**
     * @return list<string> TXT record values
     */
    public function txt(string $host): array;

    public function cname(string $host): ?string;

    /**
     * @return list<string> IPv4 addresses
     */
    public function a(string $host): array;
}
