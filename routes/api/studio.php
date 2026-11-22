<?php

use App\Http\Controllers\Api\V1\StudioController;
use App\Http\Middleware\ApiEtag;
use Illuminate\Support\Facades\Route;

// /api/v1 — AI Studio for the client app (module: ai_studio). Already prefixed + named api.v1.
Route::middleware('module:ai_studio')->group(function () {
    Route::get('/studio/styles', [StudioController::class, 'styles'])->middleware(ApiEtag::class)->name('studio.styles');
    // Signed (relative) photo URLs, served on the salon host so image loaders need no headers.
    Route::get('/studio/looks/{reference}/media/{which}', [StudioController::class, 'media'])->middleware('signed:relative')->name('studio.media');

    Route::middleware(['auth:sanctum', 'api.actor:client'])->group(function () {
        Route::post('/studio/consent', [StudioController::class, 'consent'])->name('studio.consent');
        Route::delete('/studio/consent', [StudioController::class, 'withdrawConsent'])->name('studio.consent.withdraw');
        Route::post('/studio/jobs', [StudioController::class, 'createJob'])->middleware('throttle:20,1')->name('studio.jobs.store');
        Route::get('/studio/jobs/{id}', [StudioController::class, 'job'])->whereNumber('id')->name('studio.jobs.show');

        Route::get('/looks', [StudioController::class, 'looks'])->middleware(ApiEtag::class)->name('looks.index');
        Route::post('/looks/{reference}/save', [StudioController::class, 'saveLook'])->name('looks.save');
        Route::delete('/looks/{reference}', [StudioController::class, 'deleteLook'])->name('looks.destroy');
    });
});
