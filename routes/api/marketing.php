<?php

use App\Http\Controllers\Api\V1\MarketingController;
use App\Http\Middleware\ApiEtag;
use Illuminate\Support\Facades\Route;

// /api/v1 — packages, push devices, inbox and referrals for the client app. Already prefixed + named api.v1.
Route::middleware('module:marketing')->group(function () {
    Route::get('/packages', [MarketingController::class, 'packages'])->middleware(ApiEtag::class)->name('packages.index');

    Route::middleware(['auth:sanctum', 'api.actor:client'])->group(function () {
        Route::get('/me/packages', [MarketingController::class, 'myPackages'])->middleware(ApiEtag::class)->name('me.packages');
        Route::post('/packages/{id}/purchase', [MarketingController::class, 'purchase'])->whereNumber('id')->middleware(['throttle:10,1', 'api.idempotent'])->name('packages.purchase');
        Route::get('/notifications', [MarketingController::class, 'notifications'])->middleware(ApiEtag::class)->name('notifications.index');
    });
});

Route::middleware(['auth:sanctum', 'api.actor:client'])->group(function () {
    // Push registration works whatever the plan (booking reminders are push-capable too).
    Route::post('/devices', [MarketingController::class, 'registerDevice'])->middleware('throttle:30,1')->name('devices.store');
    Route::delete('/devices/{token}', [MarketingController::class, 'deleteDevice'])->where('token', '.+')->name('devices.destroy');

    Route::get('/me/referral', [MarketingController::class, 'referral'])->middleware(['module:loyalty', ApiEtag::class])->name('me.referral');
});
