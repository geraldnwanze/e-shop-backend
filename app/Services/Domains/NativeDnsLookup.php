<?php

namespace App\Services\Domains;

class NativeDnsLookup implements DnsLookup
{
    public function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT) ?: [];

        return array_values(array_map(fn (array $r): string => (string) ($r['txt'] ?? ''), $records));
    }

    public function cname(string $host): ?string
    {
        $records = @dns_get_record($host, DNS_CNAME) ?: [];

        return isset($records[0]['target']) ? strtolower(rtrim($records[0]['target'], '.')) : null;
    }

    public function a(string $host): array
    {
        $records = @dns_get_record($host, DNS_A) ?: [];

        return array_values(array_map(fn (array $r): string => (string) ($r['ip'] ?? ''), $records));
    }
}
