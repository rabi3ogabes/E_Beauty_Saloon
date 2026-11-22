<?php

namespace App\Domain\CRM\Support;

use App\Domain\CRM\Models\Client;
use App\Domain\Loyalty\Actions\LoyaltyLedger;
use App\Domain\Loyalty\Models\LoyaltyEntry;

/** The core loyalty ledger plus signed manual "adjustment" entries (same locking/balance rules). */
class AdjustableLoyaltyLedger extends LoyaltyLedger
{
    public function adjust(Client $client, int $points, ?string $note = null, ?string $key = null): LoyaltyEntry
    {
        return $this->apply($client, $points, 'adjustment', null, $key, $note);
    }
}
