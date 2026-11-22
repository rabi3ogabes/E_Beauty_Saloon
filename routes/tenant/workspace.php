<?php

use App\Domain\CRM\Models\ClientPhoto;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
| Owner workspace media (tenant domains): private files streamed only to authorised team members.
*/
Route::withoutMiddleware('tenant.access:public')
    ->middleware(['auth', 'tenant.access:dashboard'])
    ->prefix('dashboard/media')
    ->name('tenant.workspace.')
    ->group(function () {
        Route::get('/client-photos/{photo}', function (ClientPhoto $photo) {
            $user = request()->user();
            abort_unless($user && $user->tenant_id === tenant()?->id && $photo->tenant_id === tenant()->id && $user->can('clients.view'), 403);
            abort_unless(Storage::disk('local')->exists($photo->path), 404);

            return Storage::disk('local')->response($photo->path, null, ['Cache-Control' => 'private, max-age=600']);
        })->name('client-photo');
    });
