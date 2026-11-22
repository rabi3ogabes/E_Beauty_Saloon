<?php

namespace App\Domain\Api\Http\Middleware;

use App\Domain\Api\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key support for unsafe requests (POST /bookings, orders, purchases).
 *
 *  - same key + same request  → the first (2xx) response is replayed with `Idempotent-Replayed: true`
 *  - same key + other payload → 422 idempotency_mismatch
 *  - same key while the first request still runs → 409 (retry shortly)
 * Keys are scoped per tenant + authenticated actor and kept 24 hours. Error responses are not stored,
 * so a client can retry after fixing the problem (e.g. picking another slot).
 */
class Idempotent
{
    public const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header(self::HEADER));
        if ($key === '' || $request->isMethodSafe()) {
            return $next($request);
        }
        if (strlen($key) > 100) {
            return response()->json(['message' => __('api.errors.idempotency_key_invalid')], 422);
        }

        $user = $request->user();
        $actor = $user ? $user->getMorphClass().':'.$user->getKey() : 'guest:'.$request->ip();
        $fingerprint = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($request->all()));

        $stored = $this->find($actor, $key);
        if ($stored) {
            return $this->replay($stored, $fingerprint);
        }

        $lock = Cache::lock('api-idem:'.tenant()?->id.':'.sha1($actor.'|'.$key), 30);
        if (! $lock->get()) {
            return response()->json(['message' => __('api.errors.idempotency_in_progress')], 409);
        }

        try {
            if ($stored = $this->find($actor, $key)) {
                return $this->replay($stored, $fingerprint);
            }
            $response = $next($request);
            if ($response->isSuccessful() && $response instanceof JsonResponse) {
                IdempotencyKey::query()->where('expires_at', '<', now())->delete();
                IdempotencyKey::create([
                    'actor' => $actor, 'key' => $key, 'fingerprint' => $fingerprint,
                    'status_code' => $response->getStatusCode(), 'response_body' => (string) $response->getContent(),
                    'expires_at' => now()->addDay(),
                ]);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    protected function find(string $actor, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()->where('actor', $actor)->where('key', $key)->where('expires_at', '>', now())->first();
    }

    protected function replay(IdempotencyKey $stored, string $fingerprint): Response
    {
        if (! hash_equals($stored->fingerprint, $fingerprint)) {
            return response()->json(['message' => __('api.errors.idempotency_mismatch')], 422);
        }

        return response($stored->response_body, $stored->status_code, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
    }
}
