<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Subscription gate:
 *  - grace: read-only (non-GET requests blocked except billing/logout/livewire reads)
 *  - suspended/cancelled: dashboard locked -> billing page; public site shows a polite "unavailable" page.
 */
class EnsureTenantIsAccessible
{
    public function handle(Request $request, Closure $next, string $area = 'dashboard'): Response
    {
        $tenant = tenant();
        if (! $tenant) {
            return $next($request);
        }

        if ($area === 'public') {
            if (! $tenant->status->canAccessDashboard()) {
                return response()->view('errors.tenant-unavailable', ['tenant' => $tenant], 503);
            }

            return $next($request);
        }

        if (! $tenant->status->canAccessDashboard()) {
            if ($request->routeIs('tenant.locked', 'tenant.billing.*', 'filament.dashboard.auth.*', 'impersonate.*')) {
                return $next($request);
            }

            return redirect()->route('tenant.locked');
        }

        if ($tenant->isReadOnly() && ! $request->isMethodSafe() && ! $request->routeIs('tenant.billing.*', 'filament.dashboard.auth.logout', 'impersonate.*')
            && ! $this->isLivewireRead($request)) {
            abort(423, __('salon.errors.read_only'));
        }

        return $next($request);
    }

    protected function isLivewireRead(Request $request): bool
    {
        // Livewire navigation/polling uses POST; writes are additionally blocked in policies via isReadOnly().
        return $request->hasHeader('X-Livewire');
    }
}
