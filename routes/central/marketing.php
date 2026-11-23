<?php

use App\Http\Controllers\Marketing\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Meta WhatsApp Cloud webhook (one URL for the platform app; tenants are resolved from the payload).
// CSRF-exempt via "webhooks/*". POST is verified with X-Hub-Signature-256.
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('marketing.webhooks.whatsapp.verify');
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])->name('marketing.webhooks.whatsapp');
