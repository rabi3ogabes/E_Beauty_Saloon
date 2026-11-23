<?php

namespace App\Support\Http;

use App\Support\Exceptions\DomainException;

/**
 * SSRF guard for tenant-configured outbound URLs (SMS gateways, generic AI providers, webhooks).
 * Requires https and a host that resolves only to public IP addresses.
 */
class SafeUrl
{
    public static function assert(string $url): string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        if (($parts['scheme'] ?? null) !== 'https' || ! $host || isset($parts['user']) || isset($parts['pass'])) {
            throw new DomainException(__('salon.errors.unsafe_url'));
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
            array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        );
        if ($ips === []) {
            throw new DomainException(__('salon.errors.unsafe_url'));
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new DomainException(__('salon.errors.unsafe_url'));
            }
        }

        return $url;
    }
}
