<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCentralDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(tenant() !== null, 404);

        return $next($request);
    }
}
