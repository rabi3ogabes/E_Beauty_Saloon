<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Models\Domain;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identification order: custom domain -> subdomain ({slug}.base) -> X-Tenant header (API only).
 * Central domains resolve no tenant.
 */
class ResolveTenant
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $tenant = $this->identify($request);
        $this->context->set($tenant);

        if ($mode === 'required' && ! $tenant) {
            abort(404);
        }
        if ($mode === 'central' && $tenant) {
            abort(404);
        }

        if ($tenant) {
            config(['app.timezone_display' => $tenant->timezone]);
            view()->share('currentTenant', $tenant);
        }

        return $next($request);
    }

    public function identify(Request $request): ?Tenant
    {
        $host = strtolower($request->getHost());

        if (! in_array($host, config('salon.central_domains'), true)) {
            $tenantId = Cache::remember('tenant-domain:'.$host, 300, function () use ($host) {
                return Domain::query()->where('domain', $host)
                    ->where(fn ($q) => $q->where('type', 'subdomain')->orWhereNotNull('verified_at'))
                    ->value('tenant_id') ?? false;
            });
            if ($tenantId) {
                return Tenant::find($tenantId);
            }
        }

        if ($request->is('api/*') && ($header = $request->header(config('salon.tenant_header')))) {
            return Tenant::query()->where('slug', $header)->orWhere('id', ctype_digit($header) ? (int) $header : 0)->first();
        }

        return null;
    }
}
