<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\Campaign;

/** Marks a campaign "sent" once nothing is queued, deferred or held any more. */
class CompleteCampaign
{
    public static function check(?Campaign $campaign): void
    {
        if (! $campaign || $campaign->status !== 'sending') {
            return;
        }
        $pending = $campaign->messages()->whereIn('status', ['queued', 'held', 'skipped_quiet_hours'])->exists();
        if (! $pending) {
            $campaign->update(['status' => 'sent', 'completed_at' => now()]);
        }
    }
}
