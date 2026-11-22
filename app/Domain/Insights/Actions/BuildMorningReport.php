<?php

namespace App\Domain\Insights\Actions;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Domain\Insights\CommandCenter\TodaySnapshot;
use App\Domain\Insights\Reports\ReportService;
use App\Domain\Insights\Support\Capacity;
use App\Domain\Insights\Support\Period;
use App\Domain\Inventory\Models\BranchStock;
use Carbon\CarbonImmutable;

/** Figures for one branch's morning report: yesterday's results + today's outlook (branch-local dates). */
class BuildMorningReport
{
    public function __construct(protected ReportService $reports, protected Capacity $capacity, protected TodaySnapshot $today) {}

    public function handle(Branch $branch, CarbonImmutable $now): array
    {
        $tz = $branch->timezone ?: Period::tz();
        $local = $now->setTimezone($tz);
        $yesterday = Period::day($local->subDay(), $tz);
        $today = Period::day($local, $tz);
        $f = ['branch_id' => $branch->id];
        $first = Booking::query()->where('branch_id', $branch->id)->whereBetween('starts_at', $today->utcBounds())
            ->whereNotIn('status', [BookingStatus::CancelledByClient->value, BookingStatus::CancelledBySalon->value])->orderBy('starts_at')->value('starts_at');
        [$target] = $this->today->target($branch, $now->subDay());

        return [
            'branch' => (string) $branch->name,
            'date' => $local->toDateString(),
            'yesterday' => [
                'revenue' => $this->reports->revenueTotal($yesterday, $f),
                'target' => $target,
                'visits' => $this->reports->noShowRate($yesterday, $f)['completed'],
                'no_shows' => $this->reports->noShowRate($yesterday, $f)['no_shows'],
                'utilization' => $this->capacity->utilization($branch, $yesterday) ?? 0.0,
            ],
            'today' => [
                'bookings' => $this->reports->bookingsCount($today, $f),
                'first' => $first ? CarbonImmutable::parse($first, 'UTC')->setTimezone($tz)->format('H:i') : null,
                'utilization' => $this->capacity->utilization($branch, $today) ?? 0.0,
            ],
            'low_stock' => BranchStock::query()->where('branch_id', $branch->id)->low()->count(),
        ];
    }

    public function text(array $r, string $locale): string
    {
        $t = fn (string $key, array $p = []) => __('insights.morning.'.$key, $p, $locale);
        $lines = [
            $t('greeting', ['branch' => $r['branch'], 'date' => $r['date']]),
            $t('yesterday', ['revenue' => money($r['yesterday']['revenue']), 'target' => $r['yesterday']['target'] ? money($r['yesterday']['target']) : '—',
                'visits' => $r['yesterday']['visits'], 'no_shows' => $r['yesterday']['no_shows'], 'utilization' => $r['yesterday']['utilization']]),
            $t('today', ['bookings' => $r['today']['bookings'], 'first' => $r['today']['first'] ?? '—', 'utilization' => $r['today']['utilization']]),
        ];
        if ($r['low_stock'] > 0) {
            $lines[] = $t('low_stock', ['count' => $r['low_stock']]);
        }

        return implode("\n", $lines);
    }
}
