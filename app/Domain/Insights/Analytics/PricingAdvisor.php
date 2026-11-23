<?php

namespace App\Domain\Insights\Analytics;

use App\Domain\Insights\Reports\ReportService;
use App\Domain\Insights\Support\Period;

/**
 * Smart pricing hints from the 4-week utilization heatmap — suggestions only, the owner approves and applies
 * prices herself (never automatic). Consecutive hours on the same weekday are merged into one window.
 *   peak     : utilization ≥ 85% → +10% suggested
 *   off-peak : utilization ≤ 30% → −15% happy-hour suggested
 * Cells need at least MIN_MINUTES of scheduled time over the period to count.
 */
class PricingAdvisor
{
    public const PEAK = 85.0;

    public const OFF_PEAK = 30.0;

    public const MIN_MINUTES = 240;

    public function __construct(protected ReportService $reports) {}

    /** @return list<array{kind:string, weekday:int, from:int, to:int, utilization:float, change_pct:int, available_minutes:int}> */
    public function hints(?Period $period = null, array $filters = []): array
    {
        $period ??= Period::preset('last_28');
        $heat = $this->reports->utilizationHeatmap($period, $filters);
        $out = [];
        for ($wd = 0; $wd < 7; $wd++) {
            $current = null;
            for ($h = 0; $h <= 24; $h++) {
                $v = $h < 24 ? $heat['matrix'][$wd][$h] : null;
                $minutes = $h < 24 ? $heat['available'][$wd][$h] : 0;
                $kind = $v === null || $minutes < self::MIN_MINUTES ? null : ($v >= self::PEAK ? 'peak' : ($v <= self::OFF_PEAK ? 'off_peak' : null));
                if ($current && $current['kind'] !== $kind) {
                    $out[] = $this->close($current, $heat, $wd, $h);
                    $current = null;
                }
                if ($kind && ! $current) {
                    $current = ['kind' => $kind, 'from' => $h];
                }
            }
        }

        return collect($out)->sortBy([['kind', 'desc'], ['utilization', 'desc']])->values()->all();
    }

    protected function close(array $current, array $heat, int $wd, int $to): array
    {
        $available = $booked = 0;
        for ($h = $current['from']; $h < $to; $h++) {
            $available += $heat['available'][$wd][$h];
            $booked += $heat['booked'][$wd][$h];
        }

        return [
            'kind' => $current['kind'],
            'weekday' => $wd,
            'from' => $current['from'],
            'to' => $to,
            'utilization' => $available ? round($booked / $available * 100, 1) : 0.0,
            'change_pct' => $current['kind'] === 'peak' ? 10 : -15,
            'available_minutes' => $available,
        ];
    }
}
