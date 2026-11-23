<?php

namespace App\Domain\Insights\Rules;

use App\Domain\Insights\Analytics\ChurnRisk;
use App\Domain\Insights\Analytics\DemandForecast;
use App\Domain\Insights\Analytics\PricingAdvisor;
use App\Domain\Insights\Analytics\ReorderAdvisor;
use App\Domain\Insights\Reports\ReportService;
use App\Domain\Insights\Support\Period;
use Carbon\CarbonImmutable;

/**
 * Aggregated, anonymised metrics of the current tenant — the ONLY data the insight rules and the AI copywriter
 * see. Contains counts, rates, money totals and catalog names; never client names, phones or ids.
 */
class MetricsSnapshot
{
    public function __construct(
        protected ReportService $reports,
        protected ChurnRisk $churn,
        protected ReorderAdvisor $reorder,
        protected PricingAdvisor $pricing,
        protected DemandForecast $forecast,
    ) {}

    public function build(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $tz = Period::tz();
        $local = $now->setTimezone($tz);
        $last28 = Period::between($local->subDays(28), $local->subDay(), $tz);
        $last7 = Period::between($local->subDays(7), $local->subDay(), $tz);
        $prev7 = $last7->previous();

        $revenue7 = $this->reports->revenueTotal($last7);
        $revenuePrev = $this->reports->revenueTotal($prev7);
        $heat = $this->reports->utilizationHeatmap($last28);
        $reorder = $this->reorder->suggestions(now: $now);

        $busiest = null;
        foreach ($this->reports->branches() as $branch) {
            foreach (array_slice($this->forecast->forBranch($branch, 7, $now), 1) as $day) {
                if (! $busiest || $day['forecast'] > $busiest['forecast']) {
                    $busiest = ['branch' => (string) $branch->name, 'branch_id' => $branch->id] + $day;
                }
            }
        }

        return [
            'generated_for' => $local->toDateString(),
            'currency' => tenant()?->currency ?? 'QAR',
            'revenue' => ['last_7_days' => $revenue7, 'previous_7_days' => $revenuePrev,
                'change_pct' => $revenuePrev > 0 ? round(($revenue7 - $revenuePrev) / $revenuePrev * 100, 1) : null],
            'bookings' => ['last_7_days' => $this->reports->bookingsCount($last7), 'previous_7_days' => $this->reports->bookingsCount($prev7)],
            'utilization_28d' => $this->reports->utilization($last28)['rate'],
            'weak_slot' => $this->weakestBlock($heat),
            'peak_slots' => array_values(array_filter($this->pricing->hints($last28), fn ($h) => $h['kind'] === 'peak')),
            'no_show' => $this->reports->noShowRate($last28),
            'rebook' => $this->reports->rebookRate($last28),
            'churn' => $this->churn->summary($now),
            'reorder' => ['count' => count($reorder), 'items' => array_map(fn ($r) => array_intersect_key($r, array_flip(['branch_id', 'variant_id', 'item', 'suggested_qty', 'unit_cost', 'days_cover'])), $reorder)],
            'top_services' => array_map(fn ($s) => ['service' => $s['service'], 'revenue' => $s['total'], 'count' => $s['count']], array_slice($this->reports->revenueByService($last28), 0, 3)),
            'busiest_day' => $busiest ? array_intersect_key($busiest, array_flip(['branch', 'branch_id', 'date', 'weekday', 'forecast', 'booked', 'staff_needed', 'average', 'trend'])) : null,
        ];
    }

    /** Lowest-utilization 3-hour window (with enough scheduled time) over the heatmap. */
    protected function weakestBlock(array $heat): ?array
    {
        $best = null;
        for ($wd = 0; $wd < 7; $wd++) {
            for ($h = 0; $h <= 21; $h++) {
                $available = $booked = 0;
                for ($k = $h; $k < $h + 3; $k++) {
                    if ($heat['available'][$wd][$k] <= 0) {
                        continue 2;
                    }
                    $available += $heat['available'][$wd][$k];
                    $booked += $heat['booked'][$wd][$k];
                }
                if ($available < 360) {
                    continue;
                }
                $util = round($booked / $available * 100, 1);
                if (! $best || $util < $best['utilization']) {
                    $best = ['weekday' => $wd, 'from' => $h, 'to' => $h + 3, 'utilization' => $util, 'empty_pct' => round(100 - $util), 'available_hours' => intdiv($available, 60)];
                }
            }
        }

        return $best;
    }
}
