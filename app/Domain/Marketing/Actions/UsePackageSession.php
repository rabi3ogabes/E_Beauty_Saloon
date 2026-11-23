<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\ClientPackage;
use App\Domain\Marketing\Models\ClientPackageUsage;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Redeems session(s) of a client package (POS checkout / booking completion).
 * Idempotency key recommended, e.g. "package:booking:{id}". Throws when the package is not usable.
 */
class UsePackageSession
{
    public function handle(ClientPackage $clientPackage, int $sessions = 1, ?int $bookingId = null, ?int $serviceId = null, ?string $key = null): ClientPackageUsage
    {
        return DB::transaction(function () use ($clientPackage, $sessions, $bookingId, $serviceId, $key) {
            if ($key && ($existing = ClientPackageUsage::where('idempotency_key', $key)->first())) {
                return $existing;
            }
            $locked = ClientPackage::lockForUpdate()->findOrFail($clientPackage->id);
            if (! $locked->isUsable() || $locked->sessions_remaining < $sessions) {
                throw new DomainException(__('marketing.packages.errors.not_usable'));
            }
            if ($serviceId && ! $locked->package->loadMissing('items')->coversService($serviceId)) {
                throw new DomainException(__('marketing.packages.errors.service_not_covered'));
            }
            $remaining = $locked->sessions_remaining - $sessions;
            $locked->update(['sessions_remaining' => $remaining, 'status' => $remaining === 0 ? 'used_up' : 'active']);
            $clientPackage->sessions_remaining = $remaining;
            $clientPackage->status = $locked->status;

            return $locked->usages()->create([
                'booking_id' => $bookingId, 'service_id' => $serviceId, 'sessions' => $sessions,
                'idempotency_key' => $key, 'user_id' => auth()->id(), 'used_at' => now(),
            ]);
        });
    }
}
