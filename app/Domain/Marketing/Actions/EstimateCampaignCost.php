<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\Segment;
use App\Domain\Marketing\Services\MessageCredits;

/**
 * Audience + cost preview shown before sending. Each client is costed on the first channel of the
 * fallback chain they consented to (that is the channel that will be tried first).
 *
 * @return array{audience:int, reachable:int, skipped:int, by_channel:array<string,int>, cost:int}
 */
class EstimateCampaignCost
{
    public function __construct(protected MessageCredits $credits) {}

    public function handle(Campaign $campaign): array
    {
        return $this->forSegment($campaign->segment, $campaign->channels ?? [], $campaign->tenant_id);
    }

    public function forSegment(?Segment $segment, array $channels, ?int $tenantId = null): array
    {
        $tenantId ??= tenant()?->id;
        $out = ['audience' => 0, 'reachable' => 0, 'skipped' => 0, 'by_channel' => array_fill_keys($channels, 0), 'cost' => 0];
        if (! $segment || ! $channels) {
            return $out;
        }

        $segment->clients()->with('consents')->select(['id', 'phone', 'email'])->chunkById(1000, function ($clients) use (&$out, $channels) {
            foreach ($clients as $client) {
                $out['audience']++;
                $first = collect($channels)->first(fn ($ch) => $client->consents->firstWhere('channel', $ch)?->granted
                    && ($ch === 'email' ? $client->email : ($ch === 'push' ? true : $client->phone)));
                if ($first) {
                    $out['reachable']++;
                    $out['by_channel'][$first]++;
                } else {
                    $out['skipped']++;
                }
            }
        });

        foreach ($out['by_channel'] as $channel => $count) {
            $out['cost'] += $this->credits->estimate($tenantId, $channel, $count);
        }

        return $out;
    }
}
