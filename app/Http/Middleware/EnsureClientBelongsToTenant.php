<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Defense in depth: a client session is only valid on the salon that issued it. */
class EnsureClientBelongsToTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = Auth::guard('client')->user();
        if ($client && $client->tenant_id !== tenant()?->id) {
            Auth::guard('client')->logout();

            return redirect()->guest(route('client.login'));
        }

        return $next($request);
    }
}
