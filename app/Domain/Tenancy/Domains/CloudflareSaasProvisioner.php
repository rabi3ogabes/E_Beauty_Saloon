<?php

namespace App\Domain\Tenancy\Domains;

use App\Domain\Tenancy\Models\Domain;
use Illuminate\Support\Facades\Http;

/** Cloudflare for SaaS — custom hostnames API. */
class CloudflareSaasProvisioner implements DomainProvisioner
{
    public function provision(Domain $domain): string
    {
        $zone = config('salon.domains.cloudflare.zone_id');
        $response = Http::withToken(config('salon.domains.cloudflare.api_token'))
            ->post("https://api.cloudflare.com/client/v4/zones/{$zone}/custom_hostnames", [
                'hostname' => $domain->domain,
                'ssl' => ['method' => 'http', 'type' => 'dv', 'settings' => ['min_tls_version' => '1.2']],
            ]);

        if (! $response->successful()) {
            $domain->update(['last_error' => (string) data_get($response->json(), 'errors.0.message', 'Cloudflare error')]);

            return 'failed';
        }

        return data_get($response->json(), 'result.ssl.status') === 'active' ? 'active' : 'issuing';
    }

    public function deprovision(Domain $domain): void
    {
        $zone = config('salon.domains.cloudflare.zone_id');
        $id = Http::withToken(config('salon.domains.cloudflare.api_token'))
            ->get("https://api.cloudflare.com/client/v4/zones/{$zone}/custom_hostnames", ['hostname' => $domain->domain])
            ->json('result.0.id');
        if ($id) {
            Http::withToken(config('salon.domains.cloudflare.api_token'))->delete("https://api.cloudflare.com/client/v4/zones/{$zone}/custom_hostnames/{$id}");
        }
    }
}
