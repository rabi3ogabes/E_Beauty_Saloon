<?php

use App\Http\Controllers\Site\Shop\CartController;
use App\Http\Controllers\Site\Shop\CheckoutController;
use App\Http\Controllers\Site\Shop\DriverController;
use App\Http\Controllers\Site\Shop\OrderController;
use App\Http\Controllers\Site\Shop\OrderDocumentController;
use App\Http\Controllers\Site\Shop\StorefrontController;
use App\Http\Controllers\Site\Shop\WishlistController;
use Illuminate\Support\Facades\Route;

/*
| Salon shop (module "shop"): storefront, cart, checkout, order tracking, driver view, order PDFs.
*/
Route::middleware('module:shop')->group(function () {
    Route::prefix('shop')->name('shop.')->group(function () {
        Route::get('/', [StorefrontController::class, 'index'])->name('index');
        Route::get('/category/{slug}', [StorefrontController::class, 'category'])->name('category');
        Route::get('/brand/{slug}', [StorefrontController::class, 'brand'])->name('brand');
        Route::get('/search/suggest', [StorefrontController::class, 'suggest'])->middleware('throttle:60,1')->name('suggest');
        Route::get('/p/{slug}', [StorefrontController::class, 'product'])->name('product');

        Route::get('/cart', [CartController::class, 'show'])->name('cart');
        Route::post('/cart/items', [CartController::class, 'add'])->middleware('throttle:60,1')->name('cart.add');
        Route::patch('/cart/items/{item}', [CartController::class, 'update'])->name('cart.update');
        Route::delete('/cart/items/{item}', [CartController::class, 'remove'])->name('cart.remove');
        Route::post('/cart/coupon', [CartController::class, 'coupon'])->middleware('throttle:20,1')->name('cart.coupon');
        Route::delete('/cart/coupon', [CartController::class, 'removeCoupon'])->name('cart.coupon.remove');
        Route::post('/cart/gift', [CartController::class, 'gift'])->name('cart.gift');
        Route::get('/cart/recover/{token}', [CartController::class, 'recover'])->name('cart.recover');

        Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist');
        Route::post('/wishlist/{product}', [WishlistController::class, 'toggle'])->name('wishlist.toggle');

        Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');
        Route::post('/checkout/quote', [CheckoutController::class, 'quote'])->name('checkout.quote');
        Route::post('/checkout/otp', [CheckoutController::class, 'sendOtp'])->middleware('throttle:otp')->name('checkout.otp');
        Route::post('/checkout/otp/verify', [CheckoutController::class, 'verifyOtp'])->middleware('throttle:10,1')->name('checkout.otp.verify');
        Route::post('/checkout', [CheckoutController::class, 'place'])->middleware('throttle:20,1')->name('checkout.place');

        Route::get('/order/{token}', [OrderController::class, 'show'])->name('orders.show');
        Route::get('/order/{token}/return', [OrderController::class, 'paymentReturn'])->name('orders.return');
        Route::post('/order/{token}/otp', [OrderController::class, 'verifyOtp'])->middleware('throttle:10,1')->name('orders.otp');
        Route::post('/order/{token}/otp/resend', [OrderController::class, 'resendOtp'])->middleware('throttle:otp')->name('orders.otp.resend');
        Route::post('/orders/{number}/reorder', [OrderController::class, 'reorder'])->middleware(['auth:client', 'client.tenant'])->name('orders.reorder');

        // Staff documents (packing slip / invoice), AR or EN via ?lang=
        Route::middleware('auth:web')->group(function () {
            Route::get('/admin/orders/{order}/packing-slip', [OrderDocumentController::class, 'packingSlip'])->name('admin.packing-slip');
            Route::get('/admin/orders/{order}/invoice', [OrderDocumentController::class, 'invoice'])->name('admin.invoice');
        });
    });

    // Driver web view: permanent signed link per driver, or staff login.
    Route::get('/driver', [DriverController::class, 'staff'])->middleware('auth:web')->name('shop.driver.staff');
    Route::post('/driver/orders/{order}/delivered', [DriverController::class, 'staffDelivered'])->middleware('auth:web')->name('shop.driver.staff.delivered');
    Route::get('/driver/{driver}', [DriverController::class, 'show'])->middleware('signed:relative')->name('shop.driver.show');
    Route::post('/driver/{driver}/orders/{order}/delivered', [DriverController::class, 'delivered'])->middleware('signed:relative')->name('shop.driver.delivered');
});
