<?php

namespace App\Domain\CRM\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Support\AdjustableLoyaltyLedger;
use App\Domain\Loyalty\Models\LoyaltyEntry;
use App\Support\Exceptions\DomainException;

/** Manual loyalty points adjustment (signed) via the loyalty ledger. Permission-gated in the UI. */
class AdjustClientPoints
{
    public function __construct(protected AdjustableLoyaltyLedger $ledger) {}

    public function handle(Client $client, int $points, string $reason): LoyaltyEntry
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        if ($points === 0) {
            throw new DomainException(__('workspace.clients.points_nonzero'));
        }

        $entry = $this->ledger->adjust($client, $points, $reason);
        activity()->performedOn($client)->causedBy(auth()->user())->withProperties(['points' => $points, 'reason' => $reason])->log('client.points_adjusted');

        return $entry;
    }
}
