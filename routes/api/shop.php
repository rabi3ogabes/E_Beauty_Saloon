<?php

use App\Http\Controllers\Api\V1\ShopApiController;
use Illuminate\Support\Facades\Route;

// /api/v1/shop/... (already prefixed + named api.v1.)
Route::prefix('shop')->name('shop.')->middleware('module:shop')->group(function () {
    Route::get('/categories', [ShopApiController::class, 'categories'])->name('categories');
    Route::get('/products', [ShopApiController::class, 'products'])->name('products');
    Route::get('/products/{slug}', [ShopApiController::class, 'product'])->name('product');
    Route::get('/fulfilment', [ShopApiController::class, 'zones'])->name('fulfilment');
    Route::get('/cart', [ShopApiController::class, 'cart'])->name('cart');
    Route::post('/cart/items', [ShopApiController::class, 'addToCart'])->name('cart.add');
    Route::patch('/cart/items/{item}', [ShopApiController::class, 'updateCart'])->name('cart.update');
    Route::get('/orders', [ShopApiController::class, 'orders'])->middleware('auth:sanctum')->name('orders');
});
