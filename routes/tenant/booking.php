<?php

use App\Http\Controllers\Site\Booking\BookingApiController;
use App\Http\Controllers\Site\Booking\BookingFlowController;
use App\Http\Controllers\Site\Booking\ManageBookingController;
use App\Http\Controllers\Site\Booking\WaitlistClaimController;
use Illuminate\Support\Facades\Route;

/*
| Public booking flow (salon website). Deep-link contract (AI Studio "Book this look", campaigns):
|   /book?branch={slug}&service={slug[,slug]}&staff={id}&look={ref}&utm_source={src}&campaign={id}
*/
Route::middleware('module:booking')->group(function () {
    Route::get('/book', [BookingFlowController::class, 'show'])->name('booking.flow');

    Route::prefix('/book/api')->name('booking.api.')->middleware('throttle:booking')->controller(BookingApiController::class)->group(function () {
        Route::get('/catalog', 'catalog')->name('catalog');
        Route::get('/availability', 'availability')->name('availability');
        Route::post('/hold', 'hold')->name('hold');
        Route::post('/otp/send', 'sendOtp')->middleware('throttle:otp')->name('otp.send');
        Route::post('/otp/verify', 'verifyOtp')->middleware('throttle:10,1')->name('otp.verify');
        Route::post('/checkout', 'checkout')->name('checkout');
        Route::post('/waitlist', 'waitlist')->name('waitlist');
    });

    Route::get('/book/confirmation/{reference}', [BookingFlowController::class, 'confirmation'])->middleware('signed:relative')->name('booking.confirmation');
    Route::get('/book/payment-return/{reference}', [BookingFlowController::class, 'paymentReturn'])->name('booking.payment.return');

    Route::controller(ManageBookingController::class)->group(function () {
        Route::get('/booking/{reference}', 'show')->name('booking.manage');
        Route::get('/booking/{reference}/calendar.ics', 'ics')->middleware('signed:relative')->name('booking.ics');
        Route::get('/booking/{reference}/slots', 'slots')->middleware('throttle:booking')->name('booking.slots');
        Route::post('/booking/{reference}/reschedule', 'reschedule')->middleware('throttle:booking')->name('booking.reschedule');
        Route::post('/booking/{reference}/cancel', 'cancel')->middleware('throttle:booking')->name('booking.cancel');
        Route::post('/booking/{reference}/pay', 'pay')->middleware('throttle:booking')->name('booking.pay');
    });

    Route::get('/book/waitlist/claim/{entry}', [WaitlistClaimController::class, 'show'])->middleware('signed:relative')->name('booking.waitlist.claim');
    Route::post('/book/waitlist/claim/{entry}', [WaitlistClaimController::class, 'store'])->middleware('signed:relative')->name('booking.waitlist.claim.store');
});
