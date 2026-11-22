<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\AIProvider;
use Carbon\CarbonInterface;

/**
 * Margin per provider: credits revenue (charged credits × credit value) vs provider cost (USD cents
 * converted to platform minor units). Only succeeded jobs carry revenue; failures carry no charge.
 */
class AIMarginReport
{
    /** @return list<array{provider_id:?int, provider:string, driver:?string, jobs:int, credits:int, revenue:int, cost:int, margin:int, margin_percent:float}> */
    public function handle(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $creditValue = (int) config('ai.credit_value_minor');
        $rate = (float) config('ai.usd_to_platform_rate');

        $rows = AIJob::withoutGlobalScopes()
            ->where('status', 'succeeded')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw('ai_provider_id, provider_driver, COUNT(*) as jobs, SUM(charged_credits) as credits, SUM(provider_cost) as provider_cost')
            ->groupBy('ai_provider_id', 'provider_driver')
            ->get();

        $names = AIProvider::query()->whereIn('id', $rows->pluck('ai_provider_id')->filter())->pluck('name', 'id');

        return $rows->map(function ($r) use ($creditValue, $rate, $names) {
            $revenue = (int) $r->credits * $creditValue;
            $cost = (int) round((int) $r->provider_cost * $rate); // USD cents → platform minor units (1:100 both sides)

            return [
                'provider_id' => $r->ai_provider_id,
                'provider' => $names[$r->ai_provider_id] ?? ($r->provider_driver ?? '—'),
                'driver' => $r->provider_driver,
                'jobs' => (int) $r->jobs,
                'credits' => (int) $r->credits,
                'revenue' => $revenue,
                'cost' => $cost,
                'margin' => $revenue - $cost,
                'margin_percent' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : 0.0,
            ];
        })->sortByDesc('revenue')->values()->all();
    }
}
