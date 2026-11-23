<?php

use App\Http\Controllers\Webhooks\CaddyAskController;
use App\Http\Controllers\Webhooks\PlatformGatewayWebhookController;
use App\Http\Controllers\Webhooks\TenantGatewayWebhookController;
use Illuminate\Support\Facades\Route;

// Payment provider callbacks (CSRF-exempt, signature/secret verified, idempotent)
Route::post('/webhooks/gateways/{gateway}/{secret}', TenantGatewayWebhookController::class)->name('webhooks.tenant-gateway');
Route::post('/webhooks/platform/{driver}', PlatformGatewayWebhookController::class)->name('webhooks.platform');

// Caddy on-demand TLS "ask" endpoint
Route::get('/internal/caddy/ask', CaddyAskController::class)->name('caddy.ask');
