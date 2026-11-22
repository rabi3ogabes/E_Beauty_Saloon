<?php

namespace App\Domain\Api\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One error shape for every /api/v1 failure (exceptions are rendered before this runs on the way out):
 *
 *   {"message": "…", "error": {"code": "validation_failed", "status": 422}, "errors": {...}?, "module": "booking"?}
 *
 * `message` and `errors` stay where Laravel puts them (mobile ApiException reads them), `error.code` is a
 * stable machine key. Also stamps Content-Language / Vary so caches respect Accept-Language.
 */
class ApiResponseEnvelope
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());
        $response->headers->set('Vary', trim(implode(', ', array_filter([$response->headers->get('Vary'), 'Accept-Language, X-Tenant, Authorization'])), ', '));

        $status = $response->getStatusCode();
        if ($status < 400 || ! $response instanceof JsonResponse) {
            return $response;
        }

        $body = $response->getData(true);
        $body = is_array($body) ? $body : [];
        if (isset($body['error']['code'])) {
            return $response;
        }
        $body['message'] = (string) (($body['message'] ?? '') ?: __('api.errors.'.$this->code($status, $body)));
        $body['error'] = ['code' => $body['code'] ?? $this->code($status, $body), 'status' => $status];
        unset($body['code']);

        return $response->setData($body);
    }

    protected function code(int $status, array $body): string
    {
        return match (true) {
            $status === 422 && isset($body['errors']) => 'validation_failed',
            $status === 422 => 'domain_error',
            $status === 403 && isset($body['module']) => 'module_disabled',
            $status === 401 => 'unauthenticated',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 405 => 'method_not_allowed',
            $status === 409 => 'conflict',
            $status === 423 => 'read_only',
            $status === 429 => 'too_many_requests',
            $status >= 500 => 'server_error',
            default => 'error',
        };
    }
}
