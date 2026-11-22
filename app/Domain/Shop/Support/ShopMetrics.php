<?php

namespace App\Domain\Shop\Support;

use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Cart;
use App\Domain\Shop\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Shop analytics for the current tenant: revenue, AOV, conversion (orders ÷ carts), top products. */
class ShopMetrics
{
    public const PALETTE = ['#C8A96A', '#E9D6CF', '#1C1A17', '#A88B4F', '#4F7A5A'];

    protected function revenueOrders(CarbonImmutable $from, CarbonImmutable $to)
    {
        return Order::query()->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', [OrderStatus::Pending->value, OrderStatus::Cancelled->value]);
    }

    public function summary(int $days = 30): array
    {
        $to = CarbonImmutable::now();
        $from = $to->subDays($days);
        $prevFrom = $from->subDays($days);

        $current = $this->revenueOrders($from, $to)->get(['total', 'refunded']);
        $previous = $this->revenueOrders($prevFrom, $from)->get(['total', 'refunded']);
        $revenue = (int) $current->sum(fn ($o) => $o->total - $o->refunded);
        $prevRevenue = (int) $previous->sum(fn ($o) => $o->total - $o->refunded);
        $orders = Order::query()->whereBetween('created_at', [$from, $to])->count();
        $carts = Cart::query()->whereBetween('created_at', [$from, $to])->count();

        return [
            'revenue' => $revenue,
            'revenue_change' => $prevRevenue ? round(($revenue - $prevRevenue) / $prevRevenue * 100, 1) : null,
            'orders' => $current->count(),
            'aov' => $current->count() ? intdiv($revenue, $current->count()) : 0,
            'conversion' => $carts ? round(min($orders, $carts) / $carts * 100, 1) : 0.0,
            'carts' => $carts,
        ];
    }

    /** @return array<string, int> Y-m-d => revenue (minor) */
    public function revenueByDay(int $days = 30): array
    {
        $to = CarbonImmutable::now();
        $from = $to->subDays($days - 1)->startOfDay();
        $rows = $this->revenueOrders($from, $to)->get(['created_at', 'total', 'refunded'])
            ->groupBy(fn ($o) => $o->created_at->format('Y-m-d'))
            ->map(fn ($g) => (int) $g->sum(fn ($o) => $o->total - $o->refunded));
        $out = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $out[$d->format('Y-m-d')] = (int) ($rows[$d->format('Y-m-d')] ?? 0);
        }

        return $out;
    }

    /** @return array<string, int> product label => units */
    public function topProducts(int $days = 30, int $limit = 8): array
    {
        $from = CarbonImmutable::now()->subDays($days);
        $ids = $this->revenueOrders($from, CarbonImmutable::now())->pluck('id');

        return DB::table('order_items')->whereIn('order_id', $ids)
            ->select('product_id', 'name', DB::raw('sum(qty) as units'))
            ->groupBy('product_id', 'name')->orderByDesc('units')->limit($limit)->get()
            ->mapWithKeys(fn ($r) => [(json_decode((string) $r->name, true)[app()->getLocale()] ?? json_decode((string) $r->name, true)['en'] ?? '#'.$r->product_id) => (int) $r->units])
            ->all();
    }

    /** @return array<string, int> */
    public function countBy(string $column, int $days = 30): array
    {
        return Order::query()->where('created_at', '>=', now()->subDays($days))
            ->select($column, DB::raw('count(*) as c'))->groupBy($column)->pluck('c', $column)
            ->mapWithKeys(fn ($c, $k) => [(string) ($k instanceof \BackedEnum ? $k->value : $k) => (int) $c])->all();
    }
}
