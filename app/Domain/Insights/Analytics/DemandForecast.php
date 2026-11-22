<?php

namespace App\Domain\Insights\Analytics;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Domain\Insights\Support\Period;
use Carbon\CarbonImmutable;

/**
 * Explainable demand forecast per branch and day: the average bookings of the same weekday over the last
 * 4 weeks (seasonality by weekday) × a trend factor (last 28 days vs the 28 before, clipped to 0.8–1.25).
 * Staff needed = ceil(forecast × average booked hours per booking ÷ 8h shift).
 */
class DemandForecast
{
    /** @return list<array{date:string, weekday:string, history:list<int>, average:float, trend:float, forecast:float, booked:int, staff_needed:int, explanation:string}> */
    public function forBranch(Branch $branch, int $days = 14, ?CarbonImmutable $now = null): array
    {
        $tz = $branch->timezone ?: Period::tz();
        $today = ($now ?? CarbonImmutable::now())->setTimezone($tz)->startOfDay();
        $history = Period::between($today->subDays(56), $today->subDay(), $tz);
        $future = Period::between($today, $today->addDays($days - 1), $tz);

        $rows = Booking::query()->where('branch_id', $branch->id)
            ->whereNotIn('status', [BookingStatus::CancelledByClient->value, BookingStatus::CancelledBySalon->value])
            ->whereBetween('starts_at', [$history->fromUtc(), $future->untilUtc()])
            ->with('items:id,booking_id,duration_minutes')->get(['id', 'starts_at']);
        $byDate = $rows->groupBy(fn ($b) => $b->starts_at->copy()->setTimezone($tz)->format('Y-m-d'));

        $recent = $older = 0;
        foreach ($byDate as $date => $group) {
            if ($date >= $today->subDays(28)->format('Y-m-d') && $date < $today->format('Y-m-d')) {
                $recent += $group->count();
            } elseif ($date < $today->subDays(28)->format('Y-m-d')) {
                $older += $group->count();
            }
        }
        $trend = $older > 0 ? round(min(1.25, max(0.8, $recent / $older)), 2) : 1.0;
        $past = $rows->filter(fn ($b) => $b->starts_at->lt($today->utc()));
        $avgHours = $past->count() ? $past->sum(fn ($b) => $b->items->sum('duration_minutes')) / $past->count() / 60 : 1.0;

        $out = [];
        foreach ($future->dates() as $date) {
            $d = CarbonImmutable::parse($date, $tz);
            $samples = [];
            for ($w = 1; $w <= 4; $w++) {
                $samples[] = ($byDate[$d->subWeeks($w)->format('Y-m-d')] ?? collect())->count();
            }
            $avg = round(array_sum($samples) / 4, 1);
            $forecast = round($avg * $trend, 1);
            $out[] = [
                'date' => $date,
                'weekday' => $d->translatedFormat('l'),
                'history' => $samples,
                'average' => $avg,
                'trend' => $trend,
                'forecast' => $forecast,
                'booked' => ($byDate[$date] ?? collect())->count(),
                'staff_needed' => (int) ceil($forecast * $avgHours / 8),
                'explanation' => __('insights.forecast.explain', ['weekday' => $d->translatedFormat('l'), 'samples' => implode(' · ', $samples), 'avg' => $avg, 'trend' => $trend]),
            ];
        }

        return $out;
    }
}
