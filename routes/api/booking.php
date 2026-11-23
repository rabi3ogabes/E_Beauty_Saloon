<?php

use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Middleware\ApiEtag;
use Illuminate\Support\Facades\Route;

// /api/v1 — client app booking (module: booking). Already prefixed + named api.v1.
Route::middleware('module:booking')->group(function () {
    Route::get('/availability', [BookingController::class, 'availability'])->middleware(ApiEtag::class)->name('availability');

    Route::middleware(['auth:sanctum', 'api.actor:client'])->group(function () {
        Route::post('/bookings/hold', [BookingController::class, 'hold'])->middleware('throttle:booking')->name('bookings.hold');
        Route::post('/bookings', [BookingController::class, 'store'])->middleware(['throttle:booking', 'api.idempotent'])->name('bookings.store');

        Route::middleware(ApiEtag::class)->group(function () {
            Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
            Route::get('/bookings/{reference}', [BookingController::class, 'show'])->name('bookings.show');
            Route::get('/bookings/{reference}/availability', [BookingController::class, 'rescheduleAvailability'])->name('bookings.availability');
        });

        Route::post('/bookings/{reference}/cancel', [BookingController::class, 'cancel'])->middleware('api.idempotent')->name('bookings.cancel');
        Route::post('/bookings/{reference}/reschedule', [BookingController::class, 'reschedule'])->middleware(['throttle:booking', 'api.idempotent'])->name('bookings.reschedule');
    });
});
