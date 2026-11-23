<?php

use App\Domain\Insights\Models\ConsultationForm;
use App\Http\Controllers\Site\ConsultationController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Public consultation form with e-signature (unguessable token link sent before a booking).
Route::get('/consultation/{token}', [ConsultationController::class, 'show'])->where('token', '[A-Za-z0-9]{40,64}')->name('insights.consultation.show');
Route::post('/consultation/{token}', [ConsultationController::class, 'sign'])->where('token', '[A-Za-z0-9]{40,64}')->middleware('throttle:10,1')->name('insights.consultation.sign');

// Private signature PNGs, streamed only to team members who may view clients.
Route::withoutMiddleware('tenant.access:public')
    ->middleware(['auth', 'tenant.access:dashboard'])
    ->prefix('dashboard/media')
    ->name('tenant.insights.')
    ->group(function () {
        Route::get('/consultation-signatures/{form}', function (ConsultationForm $form) {
            $user = request()->user();
            abort_unless($user && $user->tenant_id === tenant()?->id && $form->tenant_id === tenant()->id && $user->can('clients.view'), 403);
            abort_unless($form->signature_path && Storage::disk('local')->exists($form->signature_path), 404);

            return Storage::disk('local')->response($form->signature_path, null, ['Cache-Control' => 'private, max-age=600']);
        })->name('consultation-signature');
    });
