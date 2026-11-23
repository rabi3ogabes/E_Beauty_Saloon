<?php

use App\Http\Controllers\Billing\InvoicePdfController;
use Illuminate\Support\Facades\Route;

/*
| Owner billing (tenant domains). Named tenant.billing.* so EnsureTenantIsAccessible lets them through
| while the dashboard is locked; the public "unavailable" gate is swapped for the dashboard gate.
| The billing page itself is the Filament page /dashboard/billing (route tenant.billing.show).
*/
Route::withoutMiddleware('tenant.access:public')
    ->middleware(['auth', 'tenant.access:dashboard'])
    ->prefix('dashboard/billing')
    ->name('tenant.billing.')
    ->group(function () {
        Route::get('/invoices/{invoice}/pdf', InvoicePdfController::class)->name('invoices.pdf');
    });
