<?php

namespace App\Domain\Tenancy\Domains;

use App\Domain\Tenancy\Models\Domain;

/**
 * Caddy on-demand TLS: Caddy calls our "ask" endpoint (route caddy.ask) before issuing a
 * certificate. A verified domain is approved there, so provisioning just marks it active.
 *
 * Caddyfile:
 *   { on_demand_tls { ask https://admin.yourbrand.com/internal/caddy/ask } }
 *   https:// { tls { on_demand } reverse_proxy app:8000 }
 */
class CaddyOnDemandProvisioner implements DomainProvisioner
{
    public function provision(Domain $domain): string
    {
        return 'active';
    }

    public function deprovision(Domain $domain): void {}
}
