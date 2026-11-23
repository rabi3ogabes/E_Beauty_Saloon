<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A Sanctum token is only valid for the salon that issued it. */
class EnsureTokenBelongsToTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if(! $user || ($user->tenant_id ?? null) !== tenant()?->id, 401, 'Unauthenticated.');

        return $next($request);
    }
}
