<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\ClientPackage;

/** Activates a paid client package: validity starts now. Idempotent. */
class ActivateClientPackage
{
    public function handle(ClientPackage $clientPackage, ?int $amountPaid = null): ClientPackage
    {
        if ($clientPackage->status !== 'pending') {
            return $clientPackage;
        }
        $validity = $clientPackage->package?->validity_days;
        $clientPackage->update([
            'status' => 'active',
            'activated_at' => now(),
            'expires_at' => $validity ? now()->addDays($validity) : null,
            'price_paid' => $amountPaid ?? $clientPackage->price_paid,
        ]);
        activity()->performedOn($clientPackage)->withProperties(['package_id' => $clientPackage->package_id])->log('package.activated');

        return $clientPackage;
    }
}
