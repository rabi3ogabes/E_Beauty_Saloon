<?php

namespace App\Domain\Tenancy\Domains;

use App\Domain\Tenancy\Models\Domain;

/** Issues/removes TLS for a verified custom domain. Drivers: caddy (on-demand TLS), cloudflare (SaaS custom hostnames), null. */
interface DomainProvisioner
{
    /** @return string New ssl_status: issuing|active|failed */
    public function provision(Domain $domain): string;

    public function deprovision(Domain $domain): void;
}
