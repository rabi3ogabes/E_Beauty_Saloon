<?php

namespace App\Domain\Insights\Rules;

/**
 * Deterministic rules over the MetricsSnapshot → insight candidates. Each candidate carries the aggregated
 * metrics it is based on, template parameters (for the fallback copy) and an optional one-click action.
 */
class InsightRules
{
    public const OFF_PEAK_EMPTY_PCT = 35;

    public const NO_SHOW_RATE = 8.0;

    public const REVENUE_SWING = 15.0;

    /** @return list<array{type:string, scope:string, priority:int, metrics:array, params:array, action:?array, branch_id:?int}> */
    public function evaluate(array $m): array
    {
        $out = [];

        if (($slot = $m['weak_slot']) && $slot['empty_pct'] >= self::OFF_PEAK_EMPTY_PCT) {
            $out[] = [
                'type' => 'off_peak', 'scope' => $slot['weekday'].'-'.$slot['from'], 'priority' => 90, 'branch_id' => null,
                'metrics' => $slot,
                'params' => ['weekday' => $slot['weekday'], 'from' => sprintf('%02d:00', $slot['from']), 'to' => sprintf('%02d:00', $slot['to']), 'empty' => $slot['empty_pct'], 'util' => $slot['utilization']],
                'action' => ['type' => 'campaign_draft', 'params' => ['kind' => 'happy_hour', 'weekday' => $slot['weekday'], 'from' => $slot['from'], 'to' => $slot['to'], 'discount' => 15]],
            ];
        }

        if (($m['reorder']['count'] ?? 0) > 0) {
            $value = array_sum(array_map(fn ($i) => $i['suggested_qty'] * $i['unit_cost'], $m['reorder']['items']));
            $out[] = [
                'type' => 'reorder', 'scope' => 'all', 'priority' => 85, 'branch_id' => null,
                'metrics' => ['items' => $m['reorder']['count'], 'value' => $value],
                'params' => ['count' => $m['reorder']['count'], 'value' => money($value)],
                'action' => ['type' => 'purchase_order_draft', 'params' => ['items' => array_map(fn ($i) => ['branch_id' => $i['branch_id'], 'product_variant_id' => $i['variant_id'], 'qty' => $i['suggested_qty'], 'unit_cost' => $i['unit_cost']], $m['reorder']['items'])]],
            ];
        }

        if (($m['churn']['count'] ?? 0) > 0) {
            $out[] = [
                'type' => 'churn_risk', 'scope' => 'all', 'priority' => 80, 'branch_id' => null,
                'metrics' => $m['churn'],
                'params' => ['count' => $m['churn']['count'], 'value' => money($m['churn']['lifetime_value']), 'days' => $m['churn']['median_overdue_days']],
                'action' => ['type' => 'campaign_draft', 'params' => ['kind' => 'win_back', 'days' => max(30, (int) $m['churn']['median_overdue_days'])]],
            ];
        }

        if (($m['no_show']['rate'] ?? 0) >= self::NO_SHOW_RATE && ($m['no_show']['no_shows'] ?? 0) >= 2) {
            $out[] = [
                'type' => 'no_show', 'scope' => 'all', 'priority' => 60, 'branch_id' => null,
                'metrics' => $m['no_show'],
                'params' => ['rate' => $m['no_show']['rate'], 'count' => $m['no_show']['no_shows']],
                'action' => ['type' => 'open_page', 'params' => ['page' => 'business-settings']],
            ];
        }

        foreach (array_slice($m['peak_slots'] ?? [], 0, 1) as $peak) {
            $out[] = [
                'type' => 'peak_pricing', 'scope' => $peak['weekday'].'-'.$peak['from'], 'priority' => 55, 'branch_id' => null,
                'metrics' => $peak,
                'params' => ['weekday' => $peak['weekday'], 'from' => sprintf('%02d:00', $peak['from']), 'to' => sprintf('%02d:00', $peak['to']), 'util' => $peak['utilization'], 'change' => $peak['change_pct']],
                'action' => ['type' => 'pricing_review', 'params' => $peak],
            ];
        }

        $change = $m['revenue']['change_pct'] ?? null;
        if ($change !== null && abs($change) >= self::REVENUE_SWING) {
            $out[] = [
                'type' => $change > 0 ? 'revenue_up' : 'revenue_down', 'scope' => 'all', 'priority' => 50, 'branch_id' => null,
                'metrics' => $m['revenue'] + ['top_services' => $m['top_services']],
                'params' => ['pct' => abs($change), 'revenue' => money($m['revenue']['last_7_days']), 'top' => $m['top_services'][0]['service'] ?? '—'],
                'action' => ['type' => 'open_page', 'params' => ['page' => 'reports/revenue']],
            ];
        }

        if (($day = $m['busiest_day']) && $day['forecast'] >= 3) {
            $out[] = [
                'type' => 'demand', 'scope' => $day['branch_id'].'-'.$day['date'], 'priority' => 40, 'branch_id' => $day['branch_id'],
                'metrics' => $day,
                'params' => ['branch' => $day['branch'], 'date' => $day['date'], 'forecast' => $day['forecast'], 'staff' => $day['staff_needed'], 'booked' => $day['booked']],
                'action' => null,
            ];
        }

        return $out;
    }
}
