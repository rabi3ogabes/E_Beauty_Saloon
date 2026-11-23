<?php

use App\Http\Controllers\Payments\PaymentFlowController;
use Illuminate\Support\Facades\Route;

Route::get('/payments/redirect', [PaymentFlowController::class, 'redirect'])->name('payments.redirect');
Route::get('/payments/return/{payment:idempotency_key}', [PaymentFlowController::class, 'return'])->name('payments.return');
// Local/test sandbox page emulating a hosted payment page (FakeGateway only).
Route::get('/payments/fake/{reference}', [PaymentFlowController::class, 'fake'])->name('payments.fake');
Route::post('/payments/fake/{reference}', [PaymentFlowController::class, 'fakeConfirm'])->name('payments.fake.confirm');
