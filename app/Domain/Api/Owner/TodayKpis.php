<?php

namespace App\Domain\Api\Owner;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\BookingItem;
use App\Domain\Branches\Models\Branch;
use App\Domain\CRM\Models\Client;
use App\Domain\POS\Enums\SaleStatus;
use App\Domain\POS\Models\Sale;
use App\Domain\POS\Models\SaleRefund;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Order;
use App\Domain\Team\Models\Staff;
use Carbon\CarbonImmutable;

/**
 * Lightweight "today" numbers for the owner app (deliberately not the reporting engine): a handful of indexed
 * aggregate queries for one local day, optionally one branch.
 *
 *  - revenue  = POS sales completed that day (excl. voided) − POS refunds issued that day + shop orders placed
 *               that day that are confirmed or further along (excl. cancelled / returned)
 *  - bookings = bookings starting that day that occupy the calendar; no-shows and cancellations separately
 *  - utilization ≈ booked service minutes ÷ (bookable staff at the branch(es) × opening minutes); staff schedules,
 *               breaks and time off are ignored on purpose (approximation)
 *  - new clients = client profiles created that day (salon-wide)
 *
 * Branch restrictions of the signed-in member apply through the models' BranchScope.
 */
class TodayKpis
{
    /** @return array<string, mixed> raw minor-unit numbers; the controller formats money. */
    public function handle(?Branch $branch, CarbonImmutable|string|null $date = null): array
    {
        $tz = $branch?->timezone ?? tenant()->timezone ?? config('salon.defaults.timezone');
        $day = $date ? CarbonImmutable::parse($date instanceof CarbonImmutable ? $date->toDateString() : $date, $tz)->startOfDay() : CarbonImmutable::now($tz)->startOfDay();
        $yesterday = $day->subDay();

        $today = $this->revenue($branch, $day);
        $prev = $this->revenue($branch, $yesterday);

        $bookings = Booking::query()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->whereBetween('starts_at', [$day->utc(), $day->endOfDay()->utc()])
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $blocking = array_map(fn (BookingStatus $s) => $s->value, BookingStatus::blocking());

        return [
            'date' => $day->toDateString(),
            'timezone' => $tz,
            'branch_id' => $branch?->id,
            'revenue_today' => $today['total'],
            'revenue_yesterday' => $prev['total'],
            'revenue_breakdown' => ['pos' => $today['pos'], 'shop' => $today['shop'], 'refunds' => $today['refunds']],
            'sales_count' => $today['sales'],
            'average_ticket' => $today['sales'] > 0 ? intdiv($today['pos_gross'], $today['sales']) : 0,
            'hourly_revenue' => $today['hourly'],
            'bookings_today' => (int) $bookings->only($blocking)->sum(),
            'bookings_completed' => (int) ($bookings[BookingStatus::Completed->value] ?? 0),
            'no_shows' => (int) ($bookings[BookingStatus::NoShow->value] ?? 0),
            'cancellations' => (int) (($bookings[BookingStatus::CancelledByClient->value] ?? 0) + ($bookings[BookingStatus::CancelledBySalon->value] ?? 0)),
            'utilization' => $this->utilization($branch, $day),
            'new_clients' => Client::query()->whereBetween('created_at', [$day->utc(), $day->endOfDay()->utc()])->count(),
        ];
    }

    /** @return array{total:int, pos:int, pos_gross:int, shop:int, refunds:int, sales:int, hourly:list<int>} */
    protected function revenue(?Branch $branch, CarbonImmutable $day): array
    {
        $from = $day->utc();
        $to = $day->endOfDay()->utc();
        $tz = $day->getTimezone();

        $sales = Sale::query()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->whereIn('status', [SaleStatus::Completed->value, SaleStatus::PartiallyRefunded->value, SaleStatus::Refunded->value])
            ->whereBetween('completed_at', [$from, $to])->get(['id', 'total', 'tips', 'completed_at']);

        $refunds = (int) SaleRefund::query()->where('kind', 'refund')->whereBetween('created_at', [$from, $to])
            ->whereHas('sale', fn ($s) => $s->when($branch, fn ($w) => $w->where('branch_id', $branch->id))) // sale's BranchScope = member restrictions
            ->sum('amount');

        $shop = tenant()->hasModule('shop') ? (int) Order::query()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->whereNotIn('status', [OrderStatus::Pending->value, OrderStatus::Cancelled->value, OrderStatus::Returned->value])
            ->whereBetween('created_at', [$from, $to])->sum('total') : 0;

        $hourly = array_fill(0, 24, 0);
        foreach ($sales as $sale) {
            $hourly[(int) $sale->completed_at->copy()->setTimezone($tz)->format('G')] += (int) $sale->total;
        }
        $hours = $this->openHours($branch, $day);
        $hourly = array_values(array_slice($hourly, $hours[0], max(1, $hours[1] - $hours[0])));

        $posGross = (int) $sales->sum('total');

        return ['total' => $posGross - $refunds + $shop, 'pos' => $posGross - $refunds, 'pos_gross' => $posGross, 'shop' => $shop, 'refunds' => $refunds, 'sales' => $sales->count(), 'hourly' => $hourly];
    }

    protected function utilization(?Branch $branch, CarbonImmutable $day): float
    {
        $branches = $branch ? collect([$branch]) : Branch::query()->active()->get();
        $capacity = 0;
        foreach ($branches as $b) {
            $hours = $b->hoursFor($day);
            if (! $hours) {
                continue;
            }
            $minutes = max(0, $this->minutes($hours['close']) - $this->minutes($hours['open']));
            $staff = Staff::query()->where('is_active', true)->where('is_bookable', true)
                ->whereHas('branches', fn ($q) => $q->withoutGlobalScopes()->where('branches.id', $b->id))->count();
            $capacity += $minutes * $staff;
        }
        if ($capacity === 0) {
            return 0.0;
        }

        $booked = (int) BookingItem::query()
            ->whereHas('booking', fn ($q) => $q->blocking()->when($branch, fn ($w) => $w->where('branch_id', $branch->id)))
            ->whereBetween('starts_at', [$day->utc(), $day->endOfDay()->utc()])->sum('duration_minutes');

        return round(min(1, $booked / $capacity), 3);
    }

    /** [openHour, closeHour) for the sparkline; whole day when closed / unknown. */
    protected function openHours(?Branch $branch, CarbonImmutable $day): array
    {
        $hours = ($branch ?? Branch::query()->active()->orderBy('sort')->first())?->hoursFor($day);
        if (! $hours) {
            return [0, 24];
        }

        return [intdiv($this->minutes($hours['open']), 60), min(24, (int) ceil($this->minutes($hours['close']) / 60))];
    }

    protected function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [0, 0]);

        return $h * 60 + $m;
    }
}
