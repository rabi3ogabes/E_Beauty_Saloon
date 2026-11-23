<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — mobile apps & integrations
|--------------------------------------------------------------------------
| Tenant resolved by domain or X-Tenant header. Each domain adds routes/api/{domain}.php.
*/

Route::prefix('v1')->name('api.v1.')->middleware(['tenant:required', 'tenant.access:public', 'throttle:api'])->group(function () {
    foreach (glob(base_path('routes/api/*.php')) as $file) {
        require $file;
    }
});
