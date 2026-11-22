<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Middleware\ApiEtag;
use App\Http\Middleware\EnsureTokenBelongsToTenant;
use Illuminate\Support\Facades\Route;

// Public catalog (cacheable)
Route::middleware(ApiEtag::class)->group(function () {
    Route::get('/salon', [CatalogController::class, 'salon'])->name('salon');
    Route::get('/branches', [CatalogController::class, 'branches'])->name('branches.index');
    Route::get('/categories', [CatalogController::class, 'categories'])->name('categories.index');
    Route::get('/services', [CatalogController::class, 'services'])->name('services.index');
    Route::get('/services/{service}', [CatalogController::class, 'service'])->name('services.show');
    Route::get('/staff', [CatalogController::class, 'staff'])->name('staff.index');
});

// Phone OTP auth
Route::post('/auth/otp', [AuthController::class, 'requestCode'])->middleware('throttle:otp')->name('auth.otp');
Route::post('/auth/verify', [AuthController::class, 'verify'])->middleware('throttle:10,1')->name('auth.verify');

// Authenticated client
Route::middleware(['auth:sanctum', EnsureTokenBelongsToTenant::class])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/me', [ProfileController::class, 'show'])->name('me');
    Route::patch('/me', [ProfileController::class, 'update'])->name('me.update');
    Route::get('/me/wallet', [ProfileController::class, 'wallet'])->name('me.wallet');
    Route::get('/me/loyalty', [ProfileController::class, 'loyalty'])->name('me.loyalty');
    Route::get('/me/export', [ProfileController::class, 'export'])->name('me.export');
    Route::delete('/me', [ProfileController::class, 'destroy'])->name('me.destroy');
});
