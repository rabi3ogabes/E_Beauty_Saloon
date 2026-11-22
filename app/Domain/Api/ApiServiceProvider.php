<?php

namespace App\Domain\Api;

use App\Domain\Api\Http\Middleware\ApiResponseEnvelope;
use App\Domain\Api\Http\Middleware\EnsureApiActor;
use App\Domain\Api\Http\Middleware\Idempotent;
use App\Domain\Api\Models\ClientDevice;
use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Services\MarketingMessenger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 10 mobile API (client, staff and owner apps). Controllers live in App\Http\Controllers\Api\V1,
 * routes in routes/api/*.php; this provider wires the API-only hooks into other modules.
 */
class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('api.actor', EnsureApiActor::class);
        $router->aliasMiddleware('api.idempotent', Idempotent::class);
        // Outermost in the api group so every error (unknown tenant, auth, throttling, priority-sorted
        // middleware) leaves with the same envelope.
        $router->prependMiddlewareToGroup('api', ApiResponseEnvelope::class);

        // Marketing push sends reach the client's most recently used app installation.
        MarketingMessenger::resolvePushTokenUsing(fn (Client $client) => ClientDevice::latestTokenFor($client));

        // Staff / owner app sign-in: 5 attempts a minute per e-mail, 30 per hour per IP.
        RateLimiter::for('team-login', fn (Request $r) => [
            Limit::perMinute(5)->by('team-login:'.tenant()?->id.':'.mb_strtolower((string) $r->input('email'))),
            Limit::perHour(30)->by('team-login-ip:'.$r->ip()),
        ]);
    }
}
