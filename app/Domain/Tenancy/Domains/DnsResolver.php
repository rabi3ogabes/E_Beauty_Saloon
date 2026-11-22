<?php

namespace App\Domain\Tenancy\Domains;

/** Thin wrapper over DNS lookups so verification can be faked in tests. */
class DnsResolver
{
    /** @return list<string> */
    public function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT) ?: [];

        return array_values(array_map(fn ($r) => $r['txt'] ?? '', $records));
    }

    /** @return list<string> */
    public function cname(string $host): array
    {
        $records = @dns_get_record($host, DNS_CNAME) ?: [];

        return array_values(array_map(fn ($r) => rtrim($r['target'] ?? '', '.'), $records));
    }

    /** @return list<string> */
    public function a(string $host): array
    {
        $records = @dns_get_record($host, DNS_A) ?: [];

        return array_values(array_map(fn ($r) => $r['ip'] ?? '', $records));
    }
}
