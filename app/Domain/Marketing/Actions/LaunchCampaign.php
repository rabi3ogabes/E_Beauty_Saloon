<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Jobs\SendCampaignBatch;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Domain\Marketing\Services\MarketingMessenger;
use App\Domain\Marketing\Services\MessageCredits;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Freezes the audience of a campaign into campaign_messages and queues the sends.
 *  - clients without consent on every selected channel → skipped_no_consent (never contacted)
 *  - A/B: ab_test_percent of the audience gets variant A or B, the rest is "held" until DecideAbWinner
 *  - platform-shared channels: the cost estimate must be covered by the message credit wallet (hard stop)
 */
class LaunchCampaign
{
    public function __construct(protected MarketingMessenger $messenger, protected MessageCredits $credits, protected EstimateCampaignCost $estimator) {}

    public function handle(Campaign $campaign): Campaign
    {
        abort_if(tenant()?->isReadOnly(), 423);
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            throw new DomainException(__('marketing.campaigns.errors.already_sent'));
        }
        if (! $campaign->segment || ! $campaign->channels) {
            throw new DomainException(__('marketing.campaigns.errors.incomplete'));
        }

        $estimate = $this->estimator->handle($campaign);
        if ($estimate['cost'] > 0 && $estimate['cost'] > $this->credits->balance($campaign->tenant_id)) {
            throw new DomainException(__('marketing.credits.insufficient', [
                'cost' => money($estimate['cost']), 'balance' => money($this->credits->balance($campaign->tenant_id)),
            ]));
        }

        DB::transaction(function () use ($campaign) {
            $variants = $campaign->ab_enabled ? $campaign->variants()->get() : collect();
            $reachable = [];
            $campaign->segment->clients()->with('consents')->chunkById(500, function ($clients) use ($campaign, &$reachable) {
                foreach ($clients as $client) {
                    /** @var Client $client */
                    if ($this->messenger->reachableChannels($client, $campaign->channels)) {
                        $reachable[] = $client->id;
                    } else {
                        CampaignMessage::firstOrCreate(['campaign_id' => $campaign->id, 'client_id' => $client->id], ['status' => 'skipped_no_consent']);
                    }
                }
            });

            if ($variants->count() >= 2) {
                shuffle($reachable);
                $testSize = max($variants->count(), (int) ceil(count($reachable) * $campaign->ab_test_percent / 100));
                foreach ($reachable as $i => $clientId) {
                    $isTest = $i < $testSize;
                    CampaignMessage::firstOrCreate(['campaign_id' => $campaign->id, 'client_id' => $clientId], [
                        'status' => $isTest ? 'queued' : 'held',
                        'campaign_variant_id' => $isTest ? $variants[$i % $variants->count()]->id : null,
                    ]);
                }
            } else {
                foreach ($reachable as $clientId) {
                    CampaignMessage::firstOrCreate(['campaign_id' => $campaign->id, 'client_id' => $clientId], ['status' => 'queued']);
                }
            }

            $campaign->update([
                'status' => $variants->count() >= 2 ? 'testing' : 'sending',
                'started_at' => now(),
                'recipients_count' => count($reachable),
            ]);
        });

        activity()->performedOn($campaign)->withProperties(['recipients' => $campaign->recipients_count])->log('campaign.launched');
        $this->dispatchQueued($campaign);

        return $campaign->refresh();
    }

    public function dispatchQueued(Campaign $campaign): void
    {
        $campaign->messages()->where('status', 'queued')->pluck('id')->chunk(50)
            ->each(fn ($ids) => SendCampaignBatch::dispatch($campaign->tenant_id, $ids->values()->all()));
        CompleteCampaign::check($campaign->fresh());
    }
}
