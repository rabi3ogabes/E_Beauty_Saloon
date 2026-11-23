<?php

namespace App\Domain\Insights\Analytics;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Models\Client;
use Carbon\CarbonImmutable;

/**
 * Clients overdue against their own visit cycle. Cycle = average gap between completed visits (min 14 days,
 * needs ≥ 2 visits). At risk when days since the last visit ≥ 1.5 × cycle and ≥ 21 days, with no upcoming
 * booking. Ranked by overdue ratio weighted by lifetime value. The list (names/phones) is for the dashboard
 * only — AI only ever receives the aggregate count.
 */
class ChurnRisk
{
    public const MIN_RATIO = 1.5;

    public const MIN_DAYS = 21;

    /** @return list<array{client_id:int, name:string, phone:string, locale:string, visits:int, cycle_days:int, days_since:int, overdue_ratio:float, lifetime_value:int, message:string}> */
    public function list(?CarbonImmutable $now = null, int $limit = 100): array
    {
        $now ??= CarbonImmutable::now();
        $visits = Booking::query()->where('status', BookingStatus::Completed->value)->whereNotNull('client_id')
            ->where('starts_at', '>=', $now->subYears(2))->orderBy('starts_at')
            ->get(['client_id', 'starts_at'])->groupBy('client_id');
        $upcoming = Booking::query()->whereIn('client_id', $visits->keys()->all() ?: [0])->where('starts_at', '>', $now)
            ->whereNotIn('status', [BookingStatus::CancelledByClient->value, BookingStatus::CancelledBySalon->value])
            ->pluck('client_id')->flip();

        $rows = [];
        foreach ($visits as $clientId => $list) {
            $days = $list->map(fn ($b) => $b->starts_at->copy()->startOfDay())->unique(fn ($d) => $d->toDateString())->values();
            if ($days->count() < 2 || isset($upcoming[$clientId])) {
                continue;
            }
            $gaps = [];
            for ($i = 1; $i < $days->count(); $i++) {
                $gaps[] = $days[$i - 1]->diffInDays($days[$i]);
            }
            $cycle = max(14, (int) round(array_sum($gaps) / count($gaps)));
            $since = (int) $days->last()->diffInDays($now);
            $ratio = round($since / $cycle, 2);
            if ($ratio < self::MIN_RATIO || $since < self::MIN_DAYS) {
                continue;
            }
            $rows[$clientId] = ['visits' => $days->count(), 'cycle_days' => $cycle, 'days_since' => $since, 'overdue_ratio' => $ratio];
        }

        $clients = Client::query()->whereIn('id', array_keys($rows) ?: [0])->where('is_blocked', false)->get()->keyBy('id');

        return collect($rows)->filter(fn ($r, $id) => isset($clients[$id]))->map(function ($r, $id) use ($clients) {
            $c = $clients[$id];
            $locale = in_array($c->locale, ['ar', 'en'], true) ? $c->locale : 'en';

            return [
                'client_id' => (int) $id,
                'name' => $c->name,
                'phone' => $c->phone,
                'locale' => $locale,
                'lifetime_value' => (int) $c->lifetime_value,
                'message' => __('insights.churn.message', ['name' => $c->firstName(), 'salon' => tenant()?->name], $locale),
            ] + $r;
        })->sortByDesc(fn ($r) => $r['overdue_ratio'] * max(1, $r['lifetime_value']))->take($limit)->values()->all();
    }

    /** Aggregate only (safe for AI). */
    public function summary(?CarbonImmutable $now = null): array
    {
        $list = collect($this->list($now, 10000));

        return [
            'count' => $list->count(),
            'lifetime_value' => (int) $list->sum('lifetime_value'),
            'median_overdue_days' => (int) ($list->pluck('days_since')->median() ?? 0),
        ];
    }
}
