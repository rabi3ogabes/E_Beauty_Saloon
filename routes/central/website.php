<?php

use App\Http\Controllers\Platform\PlatformPageController;
use App\Http\Controllers\Platform\SeoController;
use Illuminate\Support\Facades\Route;

/*
| Platform marketing website (central domains). "/" is served by HomeController.
*/
Route::get('/features/{module}', [PlatformPageController::class, 'features'])->name('platform.features');
Route::get('/pricing', [PlatformPageController::class, 'pricing'])->name('platform.pricing');
Route::get('/pricing/quote', [PlatformPageController::class, 'quote'])->middleware('throttle:120,1')->name('platform.pricing.quote');
Route::get('/demo', [PlatformPageController::class, 'demo'])->name('platform.demo');
Route::post('/demo', [PlatformPageController::class, 'storeDemo'])->middleware('throttle:10,1')->name('platform.demo.store');
Route::get('/blog', [PlatformPageController::class, 'blog'])->name('platform.blog');
Route::get('/blog/{slug}', [PlatformPageController::class, 'post'])->name('platform.blog.show');
Route::get('/help', [PlatformPageController::class, 'help'])->name('platform.help');
Route::get('/legal/{page}', [PlatformPageController::class, 'legal'])->name('platform.legal');
Route::get('/p/{slug}', [PlatformPageController::class, 'page'])->name('platform.page');
Route::get('/signup', [PlatformPageController::class, 'signup'])->name('platform.signup');
Route::post('/signup', [PlatformPageController::class, 'storeSignup'])->middleware('throttle:6,1')->name('platform.signup.store');

// Shared by central AND tenant domains (dispatched on tenant() inside): the central-only guard is lifted.
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->withoutMiddleware('tenant:central')->name('sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->withoutMiddleware('tenant:central')->name('robots');
