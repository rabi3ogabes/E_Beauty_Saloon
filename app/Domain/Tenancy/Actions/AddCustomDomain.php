<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Jobs\VerifyCustomDomain;
use App\Domain\Tenancy\Models\Domain;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Str;

class AddCustomDomain
{
    public function handle(Tenant $tenant, string $domain): Domain
    {
        $domain = Str::of($domain)->lower()->trim()->replaceMatches('#^https?://#', '')->before('/')->toString();

        if (! preg_match('/^(?!-)([a-z0-9-]{1,63}\.)+[a-z]{2,}$/', $domain)) {
            throw new DomainException(__('salon.errors.domain_invalid'));
        }
        if (str_ends_with($domain, '.'.config('salon.base_domain')) || in_array($domain, config('salon.central_domains'), true)) {
            throw new DomainException(__('salon.errors.domain_reserved'));
        }
        if (Domain::where('domain', $domain)->exists()) {
            throw new DomainException(__('salon.errors.domain_taken'));
        }

        $record = $tenant->domains()->create([
            'domain' => $domain,
            'type' => 'custom',
            'is_primary' => false,
            'dns_token' => 'luxe-verify='.Str::random(32),
            'ssl_status' => 'pending',
        ]);

        VerifyCustomDomain::dispatch($record->id)->delay(now()->addMinute());

        return $record;
    }

    /** DNS records the owner must add, for display in the dashboard. */
    public static function instructions(Domain $domain): array
    {
        $isApex = substr_count($domain->domain, '.') === 1;

        return array_values(array_filter([
            ['type' => 'TXT', 'host' => '_luxe.'.$domain->domain, 'value' => $domain->dns_token],
            $isApex
                ? ['type' => 'A', 'host' => '@', 'value' => config('salon.domains.a_record')]
                : ['type' => 'CNAME', 'host' => Str::before($domain->domain, '.'), 'value' => config('salon.domains.cname_target')],
        ]));
    }
}
