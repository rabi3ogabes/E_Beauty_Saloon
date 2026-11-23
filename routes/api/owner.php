<?php

use App\Http\Controllers\Api\V1\OwnerAppController;
use App\Http\Controllers\Api\V1\TeamAuthController;
use App\Http\Middleware\ApiEtag;
use Illuminate\Support\Facades\Route;

// /api/v1/owner/... — owner app (token ability "owner"). Already prefixed + named api.v1.
Route::prefix('owner')->name('owner.')->group(function () {
    Route::post('/auth/login', [TeamAuthController::class, 'ownerLogin'])->middleware('throttle:team-login')->name('auth.login');

    Route::middleware(['auth:sanctum', 'api.actor:owner'])->group(function () {
        Route::post('/auth/logout', [TeamAuthController::class, 'logout'])->name('auth.logout');

        Route::middleware(ApiEtag::class)->group(function () {
            Route::get('/me', [OwnerAppController::class, 'me'])->name('me');
            Route::get('/kpis', [OwnerAppController::class, 'kpis'])->name('kpis');
            Route::get('/alerts', [OwnerAppController::class, 'alerts'])->name('alerts');
            Route::get('/segments', [OwnerAppController::class, 'segments'])->middleware('module:marketing')->name('segments');
        });

        Route::post('/campaigns', [OwnerAppController::class, 'campaign'])->middleware(['module:marketing', 'throttle:10,1', 'api.idempotent'])->name('campaigns.store');
    });
});
