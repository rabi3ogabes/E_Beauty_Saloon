<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignVariant;

/**
 * A/B test resolution: after ab_wait_hours, the variant with the best click rate (or booking rate when
 * ab_metric = booking) wins; ties go to A. All held recipients then receive the winner.
 */
class DecideAbWinner
{
    public function __construct(protected LaunchCampaign $launcher) {}

    public function handle(Campaign $campaign, bool $force = false): ?CampaignVariant
    {
        if ($campaign->status !== 'testing' || $campaign->ab_decided_at) {
            return $campaign->ab_winner_variant_id ? CampaignVariant::find($campaign->ab_winner_variant_id) : null;
        }
        if (! $force && $campaign->started_at?->copy()->addHours($campaign->ab_wait_hours)->isFuture()) {
            return null;
        }

        $variants = $campaign->variants()->get();
        $scores = $variants->mapWithKeys(fn (CampaignVariant $v) => [$v->id => $this->score($campaign, $v)]);
        $best = $scores->max();
        $winner = $variants->first(fn (CampaignVariant $v) => $scores[$v->id] === $best); // ordered by key → ties go to A

        $winner->update(['is_winner' => true]);
        $campaign->update(['ab_winner_variant_id' => $winner->id, 'ab_decided_at' => now(), 'status' => 'sending']);
        $campaign->messages()->where('status', 'held')->update(['status' => 'queued', 'campaign_variant_id' => $winner->id]);
        activity()->performedOn($campaign)->withProperties(['winner' => $winner->key, 'scores' => $scores->all()])->log('campaign.ab_winner');

        $this->launcher->dispatchQueued($campaign->fresh());

        return $winner;
    }

    /** Rate in basis points so comparisons stay integer. */
    public function score(Campaign $campaign, CampaignVariant $variant): int
    {
        $sent = $variant->messages()->whereNotNull('sent_at')->count();
        if ($sent === 0) {
            return 0;
        }
        $hits = $campaign->ab_metric === 'booking'
            ? $variant->messages()->whereNotNull('booking_id')->count()
            : $variant->messages()->whereNotNull('clicked_at')->count();

        return intdiv($hits * 10000, $sent);
    }
}
