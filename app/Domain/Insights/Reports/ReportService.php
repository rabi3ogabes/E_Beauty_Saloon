<?php

namespace App\Domain\Insights\Reports;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\Insights\Support\Capacity;
use App\Domain\Insights\Support\Period;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\Review;
use App\Domain\Marketing\Services\CampaignReport;
use App\Domain\POS\Actions\BuildCommissionReport;
use App\Domain\POS\Enums\SaleStatus;
use App\Domain\POS\Models\Sale;
use App\Domain\POS\Models\SaleItem;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Order;
use App\Domain\Shop\Models\OrderItem;
use App\Domain\Team\Models\Staff;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only, tenant-scoped report queries (pure: no writes, no side effects). Every method reads through the
 * tenant + branch global scopes (branch switcher and per-user branch restrictions apply automatically) and
 * groups dates in PHP with the tenant timezone, so results are identical on SQLite and MySQL.
 *
 * Money is integer minor units. Revenue = net of refunds, excluding tips:
 *   POS  : sales completed/partially refunded/refunded → total − tips − refunded_total, dated by completed_at
 *   Shop : orders not pending/cancelled → total − refunded, dated by created_at
 * Item-level revenue (staff/service/product) uses the line total minus its refunded share.
 *
 * Supported filters: branch_id, staff_id, service_id, service_category_id, product_id, channel (pos|shop).
 */
class ReportService
{
    public const SALE_STATUSES = [SaleStatus::Completed, SaleStatus::PartiallyRefunded, SaleStatus::Refunded];

    public function __construct(protected Capacity $capacity, protected TenantContext $context) {}

    // ---------------------------------------------------------------- scaffolding

    /** Branches visible to the current user (restrictions + branch switcher), optionally narrowed by filter. */
    public function branches(array $filters = []): Collection
    {
        return Branch::query()
            ->when($this->context->branchId(), fn ($q, $id) => $q->whereKey($id))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('sort')->orderBy('id')->get();
    }

    protected function sales(Period $period, array $filters = []): Builder
    {
        return Sale::query()->whereIn('status', array_map(fn ($s) => $s->value, self::SALE_STATUSES))
            ->whereBetween('completed_at', $period->utcBounds())
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', (int) $id));
    }

    protected function orders(Period $period, array $filters = []): Builder
    {
        return Order::query()->whereNotIn('status', [OrderStatus::Pending->value, OrderStatus::Cancelled->value])
            ->whereBetween('created_at', $period->utcBounds())
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', (int) $id));
    }

    protected function channelIncludes(array $filters, string $channel): bool
    {
        return empty($filters['channel']) || $filters['channel'] === $channel;
    }

    protected function hasItemFilters(array $filters): bool
    {
        return ! empty($filters['staff_id']) || ! empty($filters['service_id']) || ! empty($filters['service_category_id']) || ! empty($filters['product_id']);
    }

    /**
     * Revenue lines with the refunded share removed.
     *
     * @return Collection<int, array{date:string, branch_id:int, channel:string, type:string, staff_id:?int, service_id:?int, product_id:?int, qty:int, amount:int}>
     */
    public function revenueLines(Period $period, array $filters = []): Collection
    {
        $lines = collect();
        $serviceIds = $this->serviceIdsFor($filters);

        if ($this->channelIncludes($filters, 'pos')) {
            $sales = $this->sales($period, $filters)->get(['id', 'branch_id', 'completed_at'])->keyBy('id');
            $variantProducts = [];
            SaleItem::query()->whereIn('sale_id', $sales->keys()->all() ?: [0])
                ->when(! empty($filters['staff_id']), fn ($q) => $q->where('staff_id', (int) $filters['staff_id']))
                ->when($serviceIds !== null, fn ($q) => $q->where('type', 'service')->where('sellable_type', (new Service)->getMorphClass())->whereIn('sellable_id', $serviceIds ?: [0]))
                ->when(! empty($filters['product_id']), fn ($q) => $q->where('type', 'product')->whereIn('sellable_id', ProductVariant::query()->where('product_id', (int) $filters['product_id'])->pluck('id')->all() ?: [0]))
                ->get(['sale_id', 'type', 'sellable_type', 'sellable_id', 'qty', 'refunded_qty', 'total', 'staff_id'])
                ->each(function (SaleItem $item) use ($sales, $period, $lines, &$variantProducts) {
                    $sale = $sales[$item->sale_id];
                    $qty = max(1, (int) $item->qty);
                    $kept = max(0, $qty - (int) $item->refunded_qty);
                    $productId = null;
                    if ($item->type === 'product' && $item->sellable_id) {
                        $productId = $variantProducts[$item->sellable_id] ??= ProductVariant::withoutGlobalScopes()->whereKey($item->sellable_id)->value('product_id');
                    }
                    $lines->push([
                        'date' => $period->localDate($sale->completed_at),
                        'branch_id' => (int) $sale->branch_id,
                        'channel' => 'pos',
                        'type' => (string) $item->type,
                        'staff_id' => $item->staff_id ? (int) $item->staff_id : null,
                        'service_id' => $item->type === 'service' ? (int) $item->sellable_id : null,
                        'product_id' => $productId ? (int) $productId : null,
                        'qty' => $kept,
                        'amount' => intdiv((int) $item->total * $kept, $qty),
                    ]);
                });
        }

        if ($this->channelIncludes($filters, 'shop') && empty($filters['staff_id']) && $serviceIds === null) {
            $orders = $this->orders($period, $filters)->get(['id', 'branch_id', 'created_at'])->keyBy('id');
            OrderItem::query()->whereIn('order_id', $orders->keys()->all() ?: [0])
                ->when(! empty($filters['product_id']), fn ($q) => $q->where('product_id', (int) $filters['product_id']))
                ->get(['order_id', 'product_id', 'qty', 'returned_qty', 'total'])
                ->each(function ($item) use ($orders, $period, $lines) {
                    $order = $orders[$item->order_id];
                    $qty = max(1, (int) $item->qty);
                    $kept = max(0, $qty - (int) $item->returned_qty);
                    $lines->push([
                        'date' => $period->localDate($order->created_at),
                        'branch_id' => (int) $order->branch_id,
                        'channel' => 'shop',
                        'type' => 'product',
                        'staff_id' => null,
                        'service_id' => null,
                        'product_id' => $item->product_id ? (int) $item->product_id : null,
                        'qty' => $kept,
                        'amount' => intdiv((int) $item->total * $kept, $qty),
                    ]);
                });
        }

        return $lines;
    }

    /** null = no service filter; otherwise the allowed service ids. */
    protected function serviceIdsFor(array $filters): ?array
    {
        if (empty($filters['service_id']) && empty($filters['service_category_id'])) {
            return null;
        }

        return Service::query()
            ->when($filters['service_id'] ?? null, fn ($q, $id) => $q->whereKey((int) $id))
            ->when($filters['service_category_id'] ?? null, fn ($q, $id) => $q->where('service_category_id', (int) $id))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    // ---------------------------------------------------------------- revenue

    /** Header-level revenue per ticket (POS) / order (shop). */
    protected function tickets(Period $period, array $filters = []): Collection
    {
        $out = collect();
        if ($this->channelIncludes($filters, 'pos')) {
            $this->sales($period, $filters)->get(['branch_id', 'completed_at', 'total', 'tips', 'refunded_total'])
                ->each(fn (Sale $s) => $out->push(['date' => $period->localDate($s->completed_at), 'branch_id' => (int) $s->branch_id, 'channel' => 'pos',
                    'amount' => max(0, (int) $s->total - (int) $s->tips - (int) $s->refunded_total)]));
        }
        if ($this->channelIncludes($filters, 'shop')) {
            $this->orders($period, $filters)->get(['branch_id', 'created_at', 'total', 'refunded'])
                ->each(fn (Order $o) => $out->push(['date' => $period->localDate($o->created_at), 'branch_id' => (int) $o->branch_id, 'channel' => 'shop',
                    'amount' => max(0, (int) $o->total - (int) $o->refunded)]));
        }

        return $out;
    }

    public function revenueTotal(Period $period, array $filters = []): int
    {
        return (int) ($this->hasItemFilters($filters) ? $this->revenueLines($period, $filters) : $this->tickets($period, $filters))->sum('amount');
    }

    /** @return array<string, int> local date => revenue */
    public function revenueDaily(Period $period, array $filters = []): array
    {
        $rows = ($this->hasItemFilters($filters) ? $this->revenueLines($period, $filters) : $this->tickets($period, $filters))
            ->groupBy('date')->map(fn ($g) => (int) $g->sum('amount'));

        return collect($period->dates())->mapWithKeys(fn ($d) => [$d => (int) ($rows[$d] ?? 0)])->all();
    }

    /** @return list<array{branch_id:int, branch:string, pos:int, shop:int, total:int, tickets:int, average_ticket:int}> */
    public function revenueByBranch(Period $period, array $filters = []): array
    {
        $tickets = $this->tickets($period, $filters)->groupBy('branch_id');

        return $this->branches($filters)->map(function (Branch $b) use ($tickets) {
            $rows = $tickets->get($b->id, collect());
            $pos = (int) $rows->where('channel', 'pos')->sum('amount');
            $shop = (int) $rows->where('channel', 'shop')->sum('amount');
            $count = $rows->where('channel', 'pos')->count();

            return ['branch_id' => $b->id, 'branch' => (string) $b->name, 'pos' => $pos, 'shop' => $shop, 'total' => $pos + $shop,
                'tickets' => $count, 'average_ticket' => $count ? intdiv($pos, $count) : 0];
        })->sortByDesc('total')->values()->all();
    }

    /** @return list<array{staff_id:int, staff:string, services:int, products:int, total:int, items:int}> */
    public function revenueByStaff(Period $period, array $filters = []): array
    {
        $lines = $this->revenueLines($period, array_merge($filters, ['channel' => 'pos']))->whereNotNull('staff_id')->groupBy('staff_id');
        $names = Staff::withoutGlobalScopes()->withTrashed()->where('tenant_id', tenant()?->id)->whereIn('id', $lines->keys()->all() ?: [0])->get()->mapWithKeys(fn ($s) => [$s->id => (string) $s->name]);

        return $lines->map(fn ($g, $id) => [
            'staff_id' => (int) $id, 'staff' => $names[$id] ?? '#'.$id,
            'services' => (int) $g->where('type', 'service')->sum('amount'),
            'products' => (int) $g->where('type', 'product')->sum('amount'),
            'total' => (int) $g->sum('amount'),
            'items' => (int) $g->sum('qty'),
        ])->sortByDesc('total')->values()->all();
    }

    /** @return list<array{service_id:int, service:string, category:?string, count:int, total:int}> */
    public function revenueByService(Period $period, array $filters = []): array
    {
        $lines = $this->revenueLines($period, array_merge($filters, ['channel' => 'pos']))->where('type', 'service')->whereNotNull('service_id')->groupBy('service_id');
        $services = Service::withoutGlobalScopes()->where('tenant_id', tenant()?->id)->with('category')->whereIn('id', $lines->keys()->all() ?: [0])->get()->keyBy('id');

        return $lines->map(fn ($g, $id) => [
            'service_id' => (int) $id,
            'service' => (string) ($services[$id]?->name ?? '#'.$id),
            'category' => $services[$id]?->category?->name,
            'count' => (int) $g->sum('qty'),
            'total' => (int) $g->sum('amount'),
        ])->sortByDesc('total')->values()->all();
    }

    /** @return list<array{product_id:int, product:string, units:int, pos:int, shop:int, total:int}> */
    public function revenueByProduct(Period $period, array $filters = []): array
    {
        $lines = $this->revenueLines($period, $filters)->where('type', 'product')->whereNotNull('product_id')->groupBy('product_id');
        $names = Product::withoutGlobalScopes()->where('tenant_id', tenant()?->id)->whereIn('id', $lines->keys()->all() ?: [0])->get()->mapWithKeys(fn ($p) => [$p->id => (string) $p->name]);

        return $lines->map(fn ($g, $id) => [
            'product_id' => (int) $id, 'product' => $names[$id] ?? '#'.$id,
            'units' => (int) $g->sum('qty'),
            'pos' => (int) $g->where('channel', 'pos')->sum('amount'),
            'shop' => (int) $g->where('channel', 'shop')->sum('amount'),
            'total' => (int) $g->sum('amount'),
        ])->sortByDesc('total')->values()->all();
    }

    /** POS tickets: count and average net ticket. */
    public function averageTicket(Period $period, array $filters = []): array
    {
        $tickets = $this->tickets($period, array_merge($filters, ['channel' => 'pos']));
        $count = $tickets->count();

        return ['tickets' => $count, 'revenue' => (int) $tickets->sum('amount'), 'average' => $count ? intdiv((int) $tickets->sum('amount'), $count) : 0];
    }

    /** Average and distribution of client lifetime value (clients with at least one visit). */
    public function ltv(array $filters = []): array
    {
        $q = Client::query()->where('visits_count', '>', 0)
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('preferred_branch_id', (int) $id));
        $count = (clone $q)->count();
        $sum = (int) (clone $q)->sum('lifetime_value');
        $visits = (int) (clone $q)->sum('visits_count');

        return [
            'clients' => $count,
            'average' => $count ? intdiv($sum, $count) : 0,
            'total' => $sum,
            'average_visits' => $count ? round($visits / $count, 1) : 0.0,
            'value_per_visit' => $visits ? intdiv($sum, $visits) : 0,
        ];
    }

    public function commissionPayouts(Period $period, array $filters = []): array
    {
        return app(BuildCommissionReport::class)->handle($period->from, $period->until, isset($filters['branch_id']) ? (int) $filters['branch_id'] : null);
    }

    // ---------------------------------------------------------------- bookings & capacity

    protected function bookings(Period $period, array $filters = []): Builder
    {
        return Booking::query()->whereBetween('starts_at', $period->utcBounds())
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', (int) $id))
            ->when($filters['staff_id'] ?? null, fn ($q, $id) => $q->whereHas('items', fn ($i) => $i->where('staff_id', (int) $id)))
            ->when($this->serviceIdsFor($filters), fn ($q, $ids) => $q->whereHas('items', fn ($i) => $i->whereIn('service_id', $ids)));
    }

    public function bookingsCount(Period $period, array $filters = []): int
    {
        return $this->bookings($period, $filters)
            ->whereNotIn('status', [BookingStatus::CancelledByClient->value, BookingStatus::CancelledBySalon->value])->count();
    }

    /** @return array<string, int> local date => bookings */
    public function bookingsDaily(Period $period, array $filters = []): array
    {
        $rows = $this->bookings($period, $filters)->whereNotIn('status', [BookingStatus::CancelledByClient->value, BookingStatus::CancelledBySalon->value])
            ->get(['starts_at'])->groupBy(fn ($b) => $period->localDate($b->starts_at))->map->count();

        return collect($period->dates())->mapWithKeys(fn ($d) => [$d => (int) ($rows[$d] ?? 0)])->all();
    }

    /** No-shows ÷ (completed + no-shows) for bookings that started in the period. */
    public function noShowRate(Period $period, array $filters = []): array
    {
        $counts = $this->bookings($period, $filters)->whereIn('status', [BookingStatus::Completed->value, BookingStatus::NoShow->value])
            ->get(['status'])->countBy(fn ($b) => $b->status->value);
        $noShows = (int) ($counts[BookingStatus::NoShow->value] ?? 0);
        $completed = (int) ($counts[BookingStatus::Completed->value] ?? 0);
        $base = $noShows + $completed;

        return ['no_shows' => $noShows, 'completed' => $completed, 'rate' => $base ? round($noShows / $base * 100, 1) : 0.0];
    }

    /** Share of completed visits in the period whose client booked again within $windowDays after the visit. */
    public function rebookRate(Period $period, array $filters = [], int $windowDays = 60): array
    {
        $visits = $this->bookings($period, $filters)->where('status', BookingStatus::Completed->value)->whereNotNull('client_id')->get(['id', 'client_id', 'starts_at']);
        if ($visits->isEmpty()) {
            return ['visits' => 0, 'rebooked' => 0, 'rate' => 0.0, 'window_days' => $windowDays];
        }
        $later = Booking::query()->whereIn('client_id', $visits->pluck('client_id')->unique()->all())
            ->whereNotIn('status', [BookingStatus::CancelledByClient->value, BookingStatus::CancelledBySalon->value])
            ->where('starts_at', '>', $period->fromUtc())
            ->where('starts_at', '<=', $period->untilUtc()->addDays($windowDays))
            ->get(['id', 'client_id', 'starts_at'])->groupBy('client_id');

        $rebooked = $visits->filter(function (Booking $v) use ($later, $windowDays) {
            $until = $v->starts_at->copy()->addDays($windowDays);

            return $later->get($v->client_id, collect())->contains(fn ($b) => $b->id !== $v->id && $b->starts_at->gt($v->starts_at) && $b->starts_at->lte($until));
        })->count();

        return ['visits' => $visits->count(), 'rebooked' => $rebooked, 'rate' => round($rebooked / $visits->count() * 100, 1), 'window_days' => $windowDays];
    }

    /** Booked vs available staff minutes across visible branches. */
    public function utilization(Period $period, array $filters = []): array
    {
        $available = 0;
        $booked = 0;
        foreach ($this->branches($filters) as $branch) {
            $available += array_sum(array_map('array_sum', $this->capacity->available($branch, $period)));
            $booked += array_sum(array_map('array_sum', $this->capacity->booked($branch, $period)));
        }

        return ['available_minutes' => $available, 'booked_minutes' => $booked, 'rate' => $available ? round(min(100, $booked / $available * 100), 1) : 0.0];
    }

    /**
     * Utilization per weekday (0 = Sunday) × local hour, in %, aggregated over the period.
     *
     * @return array{matrix: array<int, array<int, ?float>>, available: array<int, array<int, int>>, booked: array<int, array<int, int>>, hours: list<int>}
     */
    public function utilizationHeatmap(Period $period, array $filters = []): array
    {
        $available = array_fill(0, 7, array_fill(0, 24, 0));
        $booked = array_fill(0, 7, array_fill(0, 24, 0));
        $add = function (array &$target, array $data) {
            foreach ($data as $date => $hours) {
                $wd = CarbonImmutable::parse($date)->dayOfWeek;
                foreach ($hours as $h => $m) {
                    $target[$wd][$h] += $m;
                }
            }
        };
        foreach ($this->branches($filters) as $branch) {
            $add($available, $this->capacity->available($branch, $period));
            $add($booked, $this->capacity->booked($branch, $period));
        }
        $matrix = [];
        $active = [];
        for ($wd = 0; $wd < 7; $wd++) {
            for ($h = 0; $h < 24; $h++) {
                $matrix[$wd][$h] = $available[$wd][$h] > 0 ? round(min(100, $booked[$wd][$h] / $available[$wd][$h] * 100), 1) : null;
                if ($available[$wd][$h] > 0) {
                    $active[$h] = true;
                }
            }
        }
        $hours = array_keys($active);
        sort($hours);

        return ['matrix' => $matrix, 'available' => $available, 'booked' => $booked, 'hours' => $hours ?: range(10, 21)];
    }

    /**
     * Monthly retention cohorts by first completed visit: % of each cohort that visited again in month +1..+n.
     *
     * @return list<array{cohort:string, size:int, retention:list<?float>}>
     */
    public function retentionCohorts(int $months = 6, array $filters = [], ?CarbonImmutable $now = null): array
    {
        $tz = Period::tz();
        $now = ($now ?? CarbonImmutable::now())->setTimezone($tz);
        $start = $now->startOfMonth()->subMonths($months - 1);
        $visits = Booking::query()->where('status', BookingStatus::Completed->value)->whereNotNull('client_id')
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', (int) $id))
            ->get(['client_id', 'starts_at'])
            ->groupBy('client_id')
            ->map(fn ($rows) => $rows->map(fn ($b) => $b->starts_at->copy()->setTimezone($tz)->format('Y-m'))->unique()->sort()->values());

        $cohorts = [];
        foreach ($visits as $clientMonths) {
            $first = $clientMonths->first();
            if ($first < $start->format('Y-m')) {
                continue;
            }
            $cohorts[$first][] = $clientMonths->all();
        }
        ksort($cohorts);

        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->addMonths($i);
            $key = $month->format('Y-m');
            $members = $cohorts[$key] ?? [];
            $size = count($members);
            $retention = [];
            for ($k = 1; $k < $months - $i; $k++) {
                $target = $month->addMonths($k)->format('Y-m');
                $retention[] = $size ? round(count(array_filter($members, fn ($m) => in_array($target, $m, true))) / $size * 100, 1) : null;
            }
            $out[] = ['cohort' => $key, 'size' => $size, 'retention' => $retention];
        }

        return $out;
    }

    public function newClients(Period $period): int
    {
        return Client::query()->whereBetween('created_at', $period->utcBounds())->count();
    }

    // ---------------------------------------------------------------- marketing

    /** @return list<array{campaign_id:int, name:string, sent:int, booked:int, revenue:int, cost:int, roi:?float}> */
    public function campaignRoi(Period $period): array
    {
        $report = app(CampaignReport::class);

        return Campaign::query()->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('started_at', $period->utcBounds())->orderByDesc('started_at')->get()
            ->map(function (Campaign $c) use ($report) {
                $r = $report->for($c);

                return ['campaign_id' => $c->id, 'name' => $c->name, 'sent' => (int) $r['sent'], 'booked' => (int) $r['booked'],
                    'revenue' => (int) $r['revenue'], 'cost' => (int) $r['cost'], 'roi' => $r['roi']];
            })->values()->all();
    }

    // ---------------------------------------------------------------- operations

    /**
     * Overbooking detector: the same staff member booked on overlapping services (manual overrides, imports,
     * offline edits). Returns one row per conflicting pair.
     *
     * @return list<array{staff_id:int, staff:string, branch:string, starts_at:string, a:string, b:string, overlap_minutes:int}>
     */
    public function overbookings(Period $period, array $filters = []): array
    {
        $bookings = $this->bookings($period, $filters)
            ->whereNotIn('status', [BookingStatus::CancelledByClient->value, BookingStatus::CancelledBySalon->value, BookingStatus::NoShow->value])
            ->with(['items.staff', 'branch'])->get();
        $items = $bookings->flatMap(fn (Booking $b) => $b->items->map(fn ($i) => ['booking' => $b, 'item' => $i]))
            ->filter(fn ($r) => $r['item']->staff_id)->groupBy(fn ($r) => $r['item']->staff_id);

        $out = [];
        foreach ($items as $staffId => $rows) {
            $rows = $rows->sortBy(fn ($r) => $r['item']->starts_at)->values();
            for ($i = 0; $i < $rows->count(); $i++) {
                for ($j = $i + 1; $j < $rows->count(); $j++) {
                    $a = $rows[$i];
                    $b = $rows[$j];
                    if ($b['item']->starts_at->gte($a['item']->ends_at)) {
                        break;
                    }
                    if ($a['booking']->id === $b['booking']->id) {
                        continue;
                    }
                    $overlap = (int) $b['item']->starts_at->diffInMinutes($a['item']->ends_at->lt($b['item']->ends_at) ? $a['item']->ends_at : $b['item']->ends_at);
                    $tz = $a['booking']->branch?->timezone ?? Period::tz();
                    $out[] = [
                        'staff_id' => (int) $staffId,
                        'staff' => (string) ($a['item']->staff?->name ?? '#'.$staffId),
                        'branch' => (string) $a['booking']->branch?->name,
                        'starts_at' => $b['item']->starts_at->copy()->setTimezone($tz)->format('Y-m-d H:i'),
                        'a' => $a['booking']->reference,
                        'b' => $b['booking']->reference,
                        'overlap_minutes' => $overlap,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Staff scorecards: revenue, services, utilization, rebook rate, no-shows, rating and retail attach rate.
     *
     * @return list<array<string, mixed>>
     */
    public function staffScorecards(Period $period, array $filters = []): array
    {
        $revenue = collect($this->revenueByStaff($period, $filters))->keyBy('staff_id');
        $staff = Staff::query()->where('is_active', true)->orderBy('sort')->get();
        $branches = $this->branches($filters);
        $reviews = Review::query()->whereBetween('created_at', $period->utcBounds())->whereNotNull('staff_id')->get(['staff_id', 'rating'])->groupBy('staff_id');

        $bookingItems = $this->bookings($period, $filters)->with('items:id,booking_id,staff_id')->get(['id', 'client_id', 'status', 'starts_at', 'branch_id']);

        return $staff->map(function (Staff $s) use ($revenue, $branches, $reviews, $bookingItems, $period, $filters) {
            $mine = $bookingItems->filter(fn ($b) => $b->items->contains('staff_id', $s->id));
            $completed = $mine->where('status', BookingStatus::Completed)->count();
            $noShows = $mine->where('status', BookingStatus::NoShow)->count();
            $booked = 0;
            foreach ($branches as $branch) {
                $booked += array_sum(array_map('array_sum', $this->capacity->booked($branch, $period, $s->id)));
            }
            $available = $this->staffAvailableMinutes($s, $branches, $period);
            $rebook = $this->rebookRate($period, array_merge($filters, ['staff_id' => $s->id]));
            $r = $revenue->get($s->id);
            $ratings = $reviews->get($s->id, collect());

            return [
                'staff_id' => $s->id,
                'staff' => (string) $s->name,
                'revenue' => (int) ($r['total'] ?? 0),
                'services_revenue' => (int) ($r['services'] ?? 0),
                'retail_revenue' => (int) ($r['products'] ?? 0),
                'retail_attach' => ($r['total'] ?? 0) > 0 ? round(($r['products'] ?? 0) / $r['total'] * 100, 1) : 0.0,
                'visits' => $completed,
                'no_shows' => $noShows,
                'utilization' => $available ? round(min(100, $booked / $available * 100), 1) : 0.0,
                'rebook_rate' => $rebook['rate'],
                'rating' => $ratings->count() ? round($ratings->avg('rating'), 2) : null,
                'reviews' => $ratings->count(),
            ];
        })->filter(fn ($row) => $row['visits'] + $row['revenue'] + $row['no_shows'] > 0 || $row['utilization'] > 0)->sortByDesc('revenue')->values()->all();
    }

    protected function staffAvailableMinutes(Staff $staff, Collection $branches, Period $period): int
    {
        $total = 0;
        foreach ($branches as $branch) {
            if (! $staff->branches()->withoutGlobalScopes()->where('branches.id', $branch->id)->exists()) {
                continue;
            }
            $total += array_sum(array_map('array_sum', $this->capacity->available($branch, $period, $staff->id)));
        }

        return $total;
    }
}
