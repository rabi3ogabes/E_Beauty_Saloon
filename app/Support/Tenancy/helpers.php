<?php

use App\Domain\Tenancy\Models\Tenant;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;

if (! function_exists('tenant')) {
    /** The tenant active for this request/job, or null on central domains. */
    function tenant(): ?Tenant
    {
        return app(TenantContext::class)->tenant();
    }
}

if (! function_exists('money')) {
    /** Format integer minor units as a localized money string. */
    function money(?int $minor, ?string $currency = null): string
    {
        return Money::of($minor ?? 0, $currency ?? tenant()?->currency ?? config('salon.defaults.currency'))->format();
    }
}
