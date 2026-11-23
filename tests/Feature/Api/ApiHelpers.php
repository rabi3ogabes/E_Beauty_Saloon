<?php

use App\Domain\CRM\Models\Client;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Laravel\Pennant\Feature;

/*
 * Shared helpers for the /api/v1 tests (required from each file).
 */

if (! function_exists('apiUrl')) {
    function apiUrl(string $path): string
    {
        return centralUrl('api/v1/'.ltrim($path, '/'));
    }

    /** X-Tenant (+ Bearer) headers for the mobile apps. */
    function apiHeaders(Tenant $tenant, ?string $token = null, array $extra = []): array
    {
        return array_filter(['X-Tenant' => $tenant->slug, 'Accept' => 'application/json', 'Authorization' => $token ? 'Bearer '.$token : null]) + $extra;
    }

    function clientToken(Client $client): string
    {
        return $client->createToken('test-phone', ['client'])->plainTextToken;
    }

    function teamToken(User $user, string $ability = 'staff'): string
    {
        return $user->createToken('test-'.$ability, [$ability])->plainTextToken;
    }

    /** Remove a module from the tenant's subscription (Pennant cache flushed). */
    function withoutModule(Tenant $tenant, string $module): void
    {
        $sub = $tenant->currentSubscription;
        $sub->update(['modules' => array_values(array_diff($sub->modules, [$module]))]);
        Feature::flushCache();
    }
}
