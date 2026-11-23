<?php

use App\Http\Controllers\Marketing\CampaignLinkController;
use App\Http\Controllers\Marketing\GiftCardController;
use App\Http\Controllers\Marketing\PackageShopController;
use App\Http\Controllers\Marketing\ReferralController;
use App\Http\Controllers\Marketing\ReviewController;
use Illuminate\Support\Facades\Route;

// Gift cards (always available — the site header links /gift-cards)
Route::get('/gift-cards', [GiftCardController::class, 'index'])->name('marketing.gift-cards.index');
Route::post('/gift-cards', [GiftCardController::class, 'store'])->middleware('throttle:10,1')->name('marketing.gift-cards.store');
Route::get('/gift-cards/thank-you/{uuid}', [GiftCardController::class, 'thankYou'])->name('marketing.gift-cards.thank-you');
Route::get('/gift-cards/{code}', [GiftCardController::class, 'show'])->middleware('signed:relative')->name('marketing.gift-cards.show');

// Campaign tracking + unsubscribe
Route::get('/c/{token}', CampaignLinkController::class)->where('token', '[A-Za-z0-9]{16,40}')->name('marketing.track');
Route::get('/u/{token}', [CampaignLinkController::class, 'unsubscribe'])->where('token', '[A-Za-z0-9]{16,40}')->name('marketing.unsubscribe');
Route::post('/u/{token}', [CampaignLinkController::class, 'confirmUnsubscribe'])->where('token', '[A-Za-z0-9]{16,40}')->name('marketing.unsubscribe.confirm');

// Packages & memberships
Route::middleware('module:marketing')->group(function () {
    Route::get('/packages', [PackageShopController::class, 'index'])->name('marketing.packages.index');
    Route::post('/packages/{package}/buy', [PackageShopController::class, 'buy'])
        ->middleware(['auth:client', 'client.tenant'])->name('marketing.packages.buy');
});

// Referrals (loyalty)
Route::get('/r/{code}', ReferralController::class)->middleware('module:loyalty')->where('code', '[A-Za-z0-9]{4,16}')->name('marketing.referral');

// Post-visit reviews — the Booking module links its post-visit messages to /review/{reference}
// Signed (relative) links only: a guessed booking reference cannot read or post someone else's review.
Route::get('/review/{reference}', [ReviewController::class, 'show'])->middleware(['signed:relative', 'throttle:30,1'])->name('marketing.review.show');
Route::post('/review/{reference}', [ReviewController::class, 'store'])->middleware(['signed:relative', 'throttle:10,1'])->name('marketing.review.store');

// Inbound SMS (STOP keyword) from SMS gateways: /webhooks/marketing/sms/{secret}?from=...&text=...
Route::match(['get', 'post'], '/webhooks/marketing/sms/{secret}', [CampaignLinkController::class, 'inboundSms'])->name('marketing.webhooks.sms');
