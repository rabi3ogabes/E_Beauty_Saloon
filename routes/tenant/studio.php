<?php

use App\Http\Controllers\Site\AtelierController;
use App\Http\Controllers\Site\StudioController;
use Illuminate\Support\Facades\Route;

/*
| AI Beauty Studio (module: ai_studio) — salon website + in-salon kiosk.
*/
Route::middleware('module:ai_studio')->prefix('studio')->name('studio.')->group(function () {
    Route::get('/', [StudioController::class, 'index'])->name('index');
    Route::get('/kiosk', [StudioController::class, 'kiosk'])->name('kiosk');

    Route::post('/consent', [StudioController::class, 'consent'])->name('consent');
    Route::post('/consent/withdraw', [StudioController::class, 'withdrawConsent'])->name('consent.withdraw');

    Route::post('/try-on', [StudioController::class, 'tryOn'])->middleware('throttle:20,1')->name('try-on');
    Route::post('/consultant', [StudioController::class, 'consultant'])->middleware('throttle:10,1')->name('consultant');
    Route::get('/jobs/{job}', [StudioController::class, 'status'])->name('status');
    Route::get('/consultations/{job}', [StudioController::class, 'consultation'])->name('consultation');

    Route::get('/looks/{look}', [StudioController::class, 'look'])->name('look');
    Route::get('/looks/{look}/share.jpg', [StudioController::class, 'share'])->middleware('signed')->name('share');
    Route::get('/media/{look}/{which}', [StudioController::class, 'media'])->middleware('signed')->name('media');

    // Live Atelier: lashes, brows, lips and nails previewed live on the device.
    Route::get('/atelier', [AtelierController::class, 'show'])->name('atelier');
    Route::post('/atelier/render', [AtelierController::class, 'render'])->middleware('throttle:20,1')->name('atelier.render');
    Route::post('/atelier/advice', [AtelierController::class, 'advice'])->middleware('throttle:10,1')->name('atelier.advice');
    Route::post('/atelier/designs', [AtelierController::class, 'store'])->middleware('throttle:30,1')->name('atelier.designs.store');
    Route::delete('/atelier/designs/{design}', [AtelierController::class, 'destroy'])->name('atelier.designs.destroy');

    Route::post('/purchase', [StudioController::class, 'purchase'])->middleware('throttle:10,1')->name('purchase');

    Route::middleware(['auth:client', 'client.tenant'])->group(function () {
        Route::post('/looks/{look}/save', [StudioController::class, 'save'])->name('look.save');
        Route::get('/my-data', [StudioController::class, 'exportData'])->name('data.export');
        Route::post('/my-data/erase', [StudioController::class, 'eraseData'])->name('data.erase');
    });
});
