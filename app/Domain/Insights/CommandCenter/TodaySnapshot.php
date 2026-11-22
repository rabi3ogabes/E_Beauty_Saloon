<?php

namespace App\Domain\Insights\CommandCenter;

use App\Domain\Branches\Models\Branch;
use App\Domain\Insights\Reports\ReportService;
use App\Domain\Insights\Support\Capacity;
use App\Domain\Insights\Support\InsightSettings;
use App\Domain\Insights\Support\Period;
use App\Domain\Inventory\Models\BranchStock;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Order;
use App\Domain\Team\Models\Staff;
use App\Domain\Team\Models\StaffSchedule;
use App\Domain\Team\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Today" per branch for the owner command center: bookings, utilization %, revenue vs target, staff on shift,
 * low stock, pending orders, unread messages. Reads through the branch scopes, so the branch switcher and
 * per-user branch restrictions apply. Sparklines cover the last 14 days.
 */
class TodaySnapshot
{
    public const OPEN_ORDER_STATUSES = [OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::ReadyForPickup];

    public function __construct(protected ReportService $reports, protected Capacity $capacity) {}

    /** @return list<array<string, mixed>> one row per visible branch */
    public function branches(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return $this->reports->branches()->map(fn (Branch $b) => $this->branch($b, $now))->all();
    }

    public function branch(Branch $branch, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $tz = $branch->timezone ?: Period::tz();
        $today = Period::day($now->setTimezone($tz), $tz);
        $filters = ['branch_id' => $branch->id];
        $revenue = $this->reports->revenueTotal($today, $filters);
        [$target, $auto] = $this->target($branch, $now);
        $onShift = $this->staffOnShift($branch, $now);

        return [
            'branch_id' => $branch->id,
            'branch' => (string) $branch->name,
            'bookings' => $this->reports->bookingsCount($today, $filters),
            'utilization' => $this->capacity->utilization($branch, $today) ?? 0.0,
            'revenue' => $revenue,
            'target' => $target,
            'target_auto' => $auto,
            'target_progress' => $target > 0 ? round($revenue / $target * 100, 1) : null,
            'staff_on_shift' => count($onShift),
            'staff_names' => $onShift,
            'low_stock' => BranchStock::query()->where('branch_id', $branch->id)->low()->count(),
            'pending_orders' => Order::query()->where('branch_id', $branch->id)->whereIn('status', array_map(fn ($s) => $s->value, self::OPEN_ORDER_STATUSES))->count(),
        ];
    }

    /** Totals across visible branches + 14-day sparklines. */
    public function totals(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $rows = collect($this->branches($now));
        $tz = Period::tz();
        $spark = Period::between($now->setTimezone($tz)->subDays(13), $now->setTimezone($tz), $tz);
        $utilSpark = [];
        $branches = $this->reports->branches();
        $available = array_fill_keys($spark->dates(), 0);
        $booked = array_fill_keys($spark->dates(), 0);
        foreach ($branches as $b) {
            foreach ($this->capacity->available($b, $spark) as $d => $h) {
                $available[$d] = ($available[$d] ?? 0) + array_sum($h);
            }
            foreach ($this->capacity->booked($b, $spark) as $d => $h) {
                $booked[$d] = ($booked[$d] ?? 0) + array_sum($h);
            }
        }
        foreach ($spark->dates() as $d) {
            $utilSpark[] = $available[$d] ? round(min(100, $booked[$d] / $available[$d] * 100), 1) : 0;
        }
        $availableToday = 0;
        $bookedToday = 0;
        foreach ($branches as $b) {
            $p = Period::day($now->setTimezone($b->timezone ?: $tz), $b->timezone ?: $tz);
            $availableToday += array_sum(array_map('array_sum', $this->capacity->available($b, $p)));
            $bookedToday += array_sum(array_map('array_sum', $this->capacity->booked($b, $p)));
        }
        $target = (int) $rows->sum('target');

        return [
            'bookings' => (int) $rows->sum('bookings'),
            'utilization' => $availableToday ? round(min(100, $bookedToday / $availableToday * 100), 1) : 0.0,
            'revenue' => (int) $rows->sum('revenue'),
            'target' => $target,
            'target_progress' => $target > 0 ? round($rows->sum('revenue') / $target * 100, 1) : null,
            'staff_on_shift' => (int) $rows->sum('staff_on_shift'),
            'low_stock' => (int) $rows->sum('low_stock'),
            'pending_orders' => (int) $rows->sum('pending_orders'),
            'unread_messages' => $this->unreadMessages(),
            'spark' => [
                'bookings' => array_values($this->reports->bookingsDaily($spark)),
                'revenue' => array_map(fn ($v) => round($v / 100, 2), array_values($this->reports->revenueDaily($spark))),
                'utilization' => $utilSpark,
            ],
        ];
    }

    /** [target minor units, auto?] — owner target per branch, tenant default, or the 28-day average. */
    public function target(Branch $branch, CarbonImmutable $now): array
    {
        $explicit = InsightSettings::get('targets.daily.'.$branch->id) ?: InsightSettings::get('targets.daily_default');
        if ($explicit) {
            return [(int) $explicit, false];
        }
        $tz = $branch->timezone ?: Period::tz();
        $local = $now->setTimezone($tz);
        $p = Period::between($local->subDays(28), $local->subDay(), $tz);

        return [intdiv($this->reports->revenueTotal($p, ['branch_id' => $branch->id]), 28), true];
    }

    /** @return list<string> names of staff working at the branch right now. */
    public function staffOnShift(Branch $branch, CarbonImmutable $now): array
    {
        $tz = $branch->timezone ?: Period::tz();
        $local = $now->setTimezone($tz);
        $hours = $branch->hoursFor($local);
        if (! $hours) {
            return [];
        }
        $time = $local->format('H:i');
        $branchOpen = $time >= $hours['open'] && ($time < $hours['close'] || $hours['close'] <= $hours['open']);

        $ids = DB::table('branch_staff')->where('branch_id', $branch->id)->pluck('staff_id')->all();
        $staff = Staff::withoutGlobalScopes()->where('tenant_id', $branch->tenant_id)->whereIn('id', $ids ?: [0])
            ->where('is_active', true)->where('is_bookable', true)->whereNull('deleted_at')->orderBy('sort')->get();
        $schedules = StaffSchedule::withoutGlobalScopes()->whereIn('staff_id', $staff->pluck('id')->all() ?: [0])->get()->groupBy('staff_id');
        $off = TimeOff::withoutGlobalScopes()->whereIn('staff_id', $staff->pluck('id')->all() ?: [0])->where('status', 'approved')
            ->where('starts_at', '<=', $now->utc())->where('ends_at', '>', $now->utc())->pluck('staff_id')->flip();

        return $staff->filter(function (Staff $s) use ($schedules, $off, $branch, $local, $time, $branchOpen) {
            if (isset($off[$s->id])) {
                return false;
            }
            $rows = $schedules->get($s->id);
            if (! $rows) {
                return $branchOpen;
            }

            return $rows->contains(fn ($r) => (int) $r->branch_id === $branch->id && (int) $r->weekday === $local->dayOfWeek
                && $time >= substr((string) $r->starts_at, 0, 5) && $time < substr((string) $r->ends_at, 0, 5));
        })->map(fn (Staff $s) => (string) $s->name)->values()->all();
    }

    public function unreadMessages(): int
    {
        if (! tenant() || ! Schema::hasTable('assistant_conversations')) {
            return 0;
        }

        return (int) DB::table('assistant_conversations')->where('tenant_id', tenant()->id)->sum('unread_count');
    }
}
