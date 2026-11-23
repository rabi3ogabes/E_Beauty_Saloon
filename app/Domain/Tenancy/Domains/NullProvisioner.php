<?php

namespace App\Domain\Tenancy\Domains;

use App\Domain\Tenancy\Models\Domain;

class NullProvisioner implements DomainProvisioner
{
    public function provision(Domain $domain): string
    {
        return 'active';
    }

    public function deprovision(Domain $domain): void {}
}
