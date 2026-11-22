<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Models\ClientMembership;
use App\Domain\Marketing\Models\Membership;

/**
 * Enrols a client in a membership after payment was taken (POS / dashboard). Prepaid terms end after term_months;
 * recurring plans renew every month (renewal charging is done by the POS/gateway — the renewal reminder automation
 * warns the client beforehand).
 */
class EnrollMembership
{
    public function handle(Membership $membership, Client $client, ?bool $autoRenew = null): ClientMembership
    {
        abort_if(tenant()?->isReadOnly(), 423);
        $months = $membership->billing === 'recurring' ? 1 : max(1, (int) $membership->term_months);
        $cm = ClientMembership::create([
            'client_id' => $client->id, 'membership_id' => $membership->id, 'status' => 'active',
            'starts_at' => now(), 'renews_at' => now()->addMonths($months),
            'auto_renew' => $autoRenew ?? $membership->billing === 'recurring', 'perks_used' => [],
        ]);
        activity()->performedOn($cm)->withProperties(['membership_id' => $membership->id])->log('membership.enrolled');

        return $cm;
    }
}
