<?php

use App\Http\Controllers\Site\AccountController;
use App\Http\Controllers\Site\ClientAuthController;
use App\Http\Middleware\EnsureClientBelongsToTenant;
use Illuminate\Support\Facades\Route;

Route::get('/login', [ClientAuthController::class, 'show'])->name('client.login');
Route::post('/login/code', [ClientAuthController::class, 'send'])->middleware('throttle:otp')->name('client.login.send');
Route::post('/login/verify', [ClientAuthController::class, 'verify'])->middleware('throttle:10,1')->name('client.login.verify');
Route::post('/logout', [ClientAuthController::class, 'logout'])->name('client.logout');

Route::middleware(['auth:client', EnsureClientBelongsToTenant::class])->group(function () {
    Route::post('/account/profile', [AccountController::class, 'updateProfile'])->name('client.account.profile');
    Route::get('/account/{section?}', AccountController::class)->name('client.account');
});
