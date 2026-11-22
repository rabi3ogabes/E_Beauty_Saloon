<?php

use App\Http\Controllers\Api\V1\ShopApiController;
use App\Http\Middleware\ApiEtag;
use Illuminate\Support\Facades\Route;

// /api/v1/shop/... (already prefixed + named api.v1.)
Route::prefix('shop')->name('shop.')->middleware('module:shop')->group(function () {
    Route::middleware(ApiEtag::class)->group(function () {
        Route::get('/categories', [ShopApiController::class, 'categories'])->name('categories');
        Route::get('/products', [ShopApiController::class, 'products'])->name('products');
        Route::get('/products/{slug}', [ShopApiController::class, 'product'])->name('product');
        Route::get('/fulfilment', [ShopApiController::class, 'zones'])->name('fulfilment');
    });
    Route::get('/cart', [ShopApiController::class, 'cart'])->name('cart');
    Route::post('/cart/items', [ShopApiController::class, 'addToCart'])->name('cart.add');
    Route::patch('/cart/items/{item}', [ShopApiController::class, 'updateCart'])->name('cart.update');

    Route::middleware(['auth:sanctum', 'api.actor:client'])->group(function () {
        Route::get('/orders', [ShopApiController::class, 'orders'])->middleware(ApiEtag::class)->name('orders');
        Route::get('/orders/{number}', [ShopApiController::class, 'order'])->middleware(ApiEtag::class)->name('orders.show');
        Route::post('/orders', [ShopApiController::class, 'placeOrder'])->middleware(['throttle:20,1', 'api.idempotent'])->name('orders.store');
    });
});
