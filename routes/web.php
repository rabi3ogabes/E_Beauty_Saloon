<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\TenantLockedController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| The same app serves the central platform (central domains) and every salon
| (subdomain / custom domain). "/" dispatches on the resolved tenant; other
| routes are split into routes/central/*.php and routes/tenant/*.php.
*/

Route::get('/', HomeController::class)->name('home');
Route::get('/locale/{locale}', LocaleController::class)->name('locale.switch');

// Central-only (platform marketing site, signup, pricing...)
Route::middleware('tenant:central')->group(function () {
    foreach (glob(base_path('routes/central/*.php')) as $file) {
        require $file;
    }
});

// Tenant-only (salon website, booking, shop, studio, account...)
Route::middleware(['tenant:required'])->group(function () {
    Route::get('/impersonate/exit', [ImpersonationController::class, 'exit'])->name('impersonate.exit');
    Route::get('/impersonate/{token}', [ImpersonationController::class, 'consume'])->name('impersonate.consume')->where('token', '[A-Za-z0-9]{64}');
    Route::get('/locked', TenantLockedController::class)->name('tenant.locked');

    Route::middleware('tenant.access:public')->group(function () {
        foreach (glob(base_path('routes/tenant/*.php')) as $file) {
            require $file;
        }
    });
});
