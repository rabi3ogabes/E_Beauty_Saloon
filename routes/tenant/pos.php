<?php

use App\Domain\POS\Http\Controllers\PosAppController;
use App\Domain\POS\Http\Controllers\ReceiptController;
use App\Domain\POS\Http\Controllers\ShiftReportController;
use App\Domain\POS\Http\Middleware\ReapplyBranchScope;
use Illuminate\Support\Facades\Route;

/*
| POS module routes (tenant domains). The terminal itself is the Filament page at /pos.
*/

// Signed public receipt (shared by WhatsApp / email). Works even if the module is later removed.
Route::get('/r/{sale}', [ReceiptController::class, 'public'])->middleware('signed:relative')->name('pos.receipt.public')->whereNumber('sale');

Route::prefix('pos')->name('pos.')->middleware('module:pos')->group(function () {
    // PWA shell (scope /pos/)
    Route::get('/sw.js', [PosAppController::class, 'serviceWorker'])->name('sw');
    Route::get('/manifest.webmanifest', [PosAppController::class, 'manifest'])->name('manifest');
    Route::get('/icon.svg', [PosAppController::class, 'icon'])->name('icon');

    // Branch restrictions re-applied (see ReapplyBranchScope).
    Route::middleware(['auth', 'tenant.access:dashboard', ReapplyBranchScope::class])->group(function () {
        Route::get('/catalog.json', [PosAppController::class, 'catalog'])->name('catalog');
        Route::post('/sync', [PosAppController::class, 'sync'])->name('sync')->middleware('throttle:30,1');

        Route::get('/sales/{sale}/receipt', [ReceiptController::class, 'print'])->name('receipt.print')->whereNumber('sale');
        Route::get('/sales/{sale}/receipt.pdf', [ReceiptController::class, 'pdf'])->name('receipt.pdf')->whereNumber('sale');
        Route::get('/shifts/{shift}/z-report', ShiftReportController::class)->name('shift.z-report')->whereNumber('shift');
    });
});
