<?php

use App\Http\Controllers\Api\V1\StaffAppController;
use App\Http\Controllers\Api\V1\TeamAuthController;
use App\Http\Middleware\ApiEtag;
use Illuminate\Support\Facades\Route;

// /api/v1/staff/... — staff app (token ability "staff"). Already prefixed + named api.v1.
Route::prefix('staff')->name('staff.')->group(function () {
    Route::post('/auth/login', [TeamAuthController::class, 'staffLogin'])->middleware('throttle:team-login')->name('auth.login');

    Route::middleware(['auth:sanctum', 'api.actor:staff'])->group(function () {
        Route::post('/auth/logout', [TeamAuthController::class, 'logout'])->name('auth.logout');

        Route::middleware(ApiEtag::class)->group(function () {
            Route::get('/me', [StaffAppController::class, 'me'])->name('me');
            Route::get('/me/calendar', [StaffAppController::class, 'calendar'])->middleware('module:booking')->name('me.calendar');
            Route::get('/me/commissions', [StaffAppController::class, 'commissions'])->middleware('module:pos')->name('me.commissions');
            Route::get('/me/time-off', [StaffAppController::class, 'timeOffIndex'])->name('me.time-off.index');
            Route::get('/clients/{id}', [StaffAppController::class, 'client'])->whereNumber('id')->name('clients.show');
        });

        Route::post('/me/time-off', [StaffAppController::class, 'requestTimeOff'])->middleware('api.idempotent')->name('me.time-off.store');
        Route::post('/bookings/{reference}/check-in', [StaffAppController::class, 'checkIn'])->middleware('module:booking')->name('bookings.check-in');
    });
});
