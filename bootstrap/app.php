<?php

use App\Http\Middleware\ApplyBranchScope;
use App\Http\Middleware\EnsureClientBelongsToTenant;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureTenantIsAccessible;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\Exceptions\DomainException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [ResolveTenant::class], append: [SetLocale::class, ApplyBranchScope::class, SecurityHeaders::class]);
        $middleware->api(prepend: [ResolveTenant::class], append: [SetLocale::class]);
        $middleware->alias([
            'module' => EnsureModuleEnabled::class,
            'tenant.access' => EnsureTenantIsAccessible::class,
            'tenant' => ResolveTenant::class,
            'client.tenant' => EnsureClientBelongsToTenant::class,
        ]);
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->redirectGuestsTo(fn (Request $request) => tenant() && ! $request->is('dashboard*', 'pos*') ? route('client.login') : '/');
        // Only trust X-Forwarded-* from known proxies (comma-separated IPs/CIDRs); defaults to private ranges (load balancer / Caddy).
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withInput()->withErrors(['domain' => $e->getMessage()]);
        });
    })->create();
