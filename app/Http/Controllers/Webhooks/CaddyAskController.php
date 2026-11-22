<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Tenancy\Models\Domain;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Caddy on-demand TLS: 200 only for domains we serve (verified custom or our subdomains). */
class CaddyAskController extends Controller
{
    public function __invoke(Request $request)
    {
        $domain = strtolower((string) $request->query('domain'));
        $ok = Domain::where('domain', $domain)->where(fn ($q) => $q->where('type', 'subdomain')->orWhereNotNull('verified_at'))->exists();

        return response($ok ? 'ok' : 'unknown', $ok ? 200 : 404);
    }
}
