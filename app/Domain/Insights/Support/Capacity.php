<?php

namespace App\Domain\Insights\Support;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Branches\Models\Branch;
use App\Domain\Team\Models\Staff;
use App\Domain\Team\Models\StaffSchedule;
use App\Domain\Team\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff capacity vs. booked minutes per branch, local date and hour — the basis of utilization %.
 * Same rules as the availability engine: schedule rows ∩ branch hours − breaks − approved time off;
 * bookable staff without any schedule row follow branch hours. Booked = service minutes of
 * non-cancelled bookings (no-shows still consumed the chair). All maths in UTC seconds, bucketed locally.
 */
class Capacity
{
    /** @return array<string, array<int, int>> Y-m-d => [hour => available staff minutes] */
    public function available(Branch $branch, Period $period, ?int $onlyStaffId = null): array
    {
        $tz = $branch->timezone ?: $period->timezone;
        $staffIds = DB::table('branch_staff')->where('branch_id', $branch->id)->pluck('staff_id')->all();
        $staff = Staff::withoutGlobalScopes()->whereIn('id', $staffIds ?: [0])->where('tenant_id', $branch->tenant_id)
            ->where('is_active', true)->where('is_bookable', true)->whereNull('deleted_at')
            ->when($onlyStaffId, fn ($q) => $q->whereKey($onlyStaffId))->pluck('id')->all();

        $schedules = StaffSchedule::withoutGlobalScopes()->whereIn('staff_id', $staff ?: [0])->get(['staff_id', 'branch_id', 'weekday', 'starts_at', 'ends_at', 'breaks']);
        $hasSchedules = $schedules->pluck('staff_id')->unique()->flip();
        $here = $schedules->where('branch_id', $branch->id)->groupBy(fn ($r) => $r->staff_id.'-'.$r->weekday);

        $timeOff = TimeOff::withoutGlobalScopes()->whereIn('staff_id', $staff ?: [0])->where('status', 'approved')
            ->where('starts_at', '<', $period->untilUtc()->addDay())->where('ends_at', '>', $period->fromUtc()->subDay())
            ->get(['staff_id', 'starts_at', 'ends_at'])->groupBy('staff_id');

        $out = [];
        foreach ($period->dates() as $ds) {
            $date = CarbonImmutable::parse($ds, $tz);
            $out[$ds] = array_fill(0, 24, 0);
            $hours = $branch->hoursFor($date);
            if (! $hours) {
                continue;
            }
            $open = $this->ts($ds, $hours['open'], $tz);
            $close = $this->ts($ds, $hours['close'], $tz);
            if ($close <= $open) {
                $close += 86400;
            }
            $branchBreaks = array_map(fn ($b) => [$this->ts($ds, $b[0], $tz), $this->ts($ds, $b[1], $tz)], array_filter((array) ($hours['breaks'] ?? []), fn ($b) => is_array($b) && count($b) === 2));

            foreach ($staff as $staffId) {
                $windows = [];
                $breaks = $branchBreaks;
                if (isset($hasSchedules[$staffId])) {
                    foreach ($here->get($staffId.'-'.$date->dayOfWeek, []) as $row) {
                        $s = max($open, $this->ts($ds, substr((string) $row->starts_at, 0, 5), $tz));
                        $e = min($close, $this->ts($ds, substr((string) $row->ends_at, 0, 5), $tz));
                        if ($e > $s) {
                            $windows[] = [$s, $e];
                        }
                        foreach ((array) $row->breaks as $b) {
                            if (is_array($b) && count($b) === 2) {
                                $breaks[] = [$this->ts($ds, $b[0], $tz), $this->ts($ds, $b[1], $tz)];
                            }
                        }
                    }
                } else {
                    $windows[] = [$open, $close];
                }
                foreach ($timeOff->get($staffId, []) as $off) {
                    $breaks[] = [CarbonImmutable::parse($off->starts_at, 'UTC')->getTimestamp(), CarbonImmutable::parse($off->ends_at, 'UTC')->getTimestamp()];
                }
                foreach (self::subtract($windows, $breaks) as [$s, $e]) {
                    $this->bucket($out, $s, $e, $tz, onlyKnownDates: true);
                }
            }
        }

        return $out;
    }

    /** @return array<string, array<int, int>> Y-m-d => [hour => booked minutes] */
    public function booked(Branch $branch, Period $period, ?int $staffId = null): array
    {
        $tz = $branch->timezone ?: $period->timezone;
        $out = [];
        foreach ($period->dates() as $ds) {
            $out[$ds] = array_fill(0, 24, 0);
        }
        $rows = DB::table('booking_items')->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->where('bookings.tenant_id', $branch->tenant_id)->where('bookings.branch_id', $branch->id)->whereNull('bookings.deleted_at')
            ->whereNotIn('bookings.status', [BookingStatus::CancelledByClient->value, BookingStatus::CancelledBySalon->value, BookingStatus::PendingPayment->value])
            ->whereBetween('booking_items.starts_at', [$period->fromUtc()->subDay(), $period->untilUtc()])
            ->when($staffId, fn ($q) => $q->where('booking_items.staff_id', $staffId))
            ->get(['booking_items.starts_at', 'booking_items.ends_at']);

        foreach ($rows as $r) {
            $s = CarbonImmutable::parse($r->starts_at, 'UTC')->getTimestamp();
            $e = CarbonImmutable::parse($r->ends_at, 'UTC')->getTimestamp();
            $this->bucket($out, $s, $e, $tz, onlyKnownDates: true);
        }

        return $out;
    }

    /** Utilization % (0–100, one decimal) for the whole period; null when nobody was scheduled. */
    public function utilization(Branch $branch, Period $period): ?float
    {
        $available = array_sum(array_map('array_sum', $this->available($branch, $period)));
        $booked = array_sum(array_map('array_sum', $this->booked($branch, $period)));

        return $available > 0 ? round(min(100, $booked / $available * 100), 1) : null;
    }

    /** Add the minutes of [s, e) to local date/hour buckets. */
    protected function bucket(array &$out, int $s, int $e, string $tz, bool $onlyKnownDates = false): void
    {
        $cursor = $s;
        while ($cursor < $e) {
            $local = CarbonImmutable::createFromTimestampUTC($cursor)->setTimezone($tz);
            $hourEnd = $local->startOfHour()->addHour()->getTimestamp();
            $chunkEnd = min($e, $hourEnd);
            $ds = $local->format('Y-m-d');
            if (isset($out[$ds]) || ! $onlyKnownDates) {
                $out[$ds] ??= array_fill(0, 24, 0);
                $out[$ds][(int) $local->format('G')] += intdiv($chunkEnd - $cursor, 60);
            }
            $cursor = $chunkEnd;
        }
    }

    protected function ts(string $date, string $time, string $tz): int
    {
        return CarbonImmutable::parse($date.' '.$time, $tz)->getTimestamp();
    }

    /** Windows minus blocked intervals. */
    public static function subtract(array $windows, array $blocked): array
    {
        foreach ($blocked as [$bs, $be]) {
            $next = [];
            foreach ($windows as [$ws, $we]) {
                if ($be <= $ws || $bs >= $we) {
                    $next[] = [$ws, $we];

                    continue;
                }
                if ($bs > $ws) {
                    $next[] = [$ws, $bs];
                }
                if ($be < $we) {
                    $next[] = [$be, $we];
                }
            }
            $windows = $next;
        }

        return $windows;
    }
}
