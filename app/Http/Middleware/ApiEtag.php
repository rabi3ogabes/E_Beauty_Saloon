<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Weak ETag + 304 for GET JSON responses (mobile apps save bandwidth). */
class ApiEtag
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $response->getStatusCode() === 200) {
            $etag = 'W/"'.md5((string) $response->getContent()).'"';
            $response->headers->set('ETag', $etag);
            if (trim((string) $request->header('If-None-Match')) === $etag) {
                $response->setStatusCode(304);
                $response->setContent('');
            }
        }

        return $response;
    }
}
