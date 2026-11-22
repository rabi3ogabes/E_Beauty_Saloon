<?php

use App\Http\Controllers\Site\PwaController;
use App\Http\Controllers\Site\SitePageController;
use Illuminate\Support\Facades\Route;

/*
| Salon website (tenant domains). "/" is served by HomeController -> SitePageController::home.
| Custom pages live under /pages/{slug} (the platform site owns /p/{slug}).
| sitemap.xml / robots.txt are shared with the platform site: see routes/central/website.php.
*/
Route::get('/branches/{slug}', [SitePageController::class, 'branch'])->name('site.branch');
Route::get('/pages/{slug}', [SitePageController::class, 'page'])->name('site.page');
Route::get('/offline', [SitePageController::class, 'offline'])->name('site.offline');

// PWA
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('site.manifest');
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('site.sw');
Route::get('/pwa/icon-{size}.png', [PwaController::class, 'icon'])->whereNumber('size')->name('site.icon');
Route::post('/push/subscribe', [PwaController::class, 'subscribe'])->middleware('throttle:20,1')->name('site.push.subscribe');
Route::post('/push/unsubscribe', [PwaController::class, 'unsubscribe'])->middleware('throttle:20,1')->name('site.push.unsubscribe');
