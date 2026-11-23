<?php

namespace App\Filament\Dashboard\Widgets\Shop;

use App\Domain\Shop\Support\ShopMetrics;
use App\Filament\Dashboard\Resources\Shop\ShopGate;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Shown on the Shop analytics page only. */
class ShopStatsOverview extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return ShopGate::view();
    }

    protected function getStats(): array
    {
        $m = app(ShopMetrics::class)->summary();
        $period = __('shop.admin.analytics.last_30');

        return [
            Stat::make(__('shop.admin.analytics.revenue'), money($m['revenue']))
                ->description($m['revenue_change'] === null ? $period : ($m['revenue_change'] >= 0 ? '+' : '').$m['revenue_change'].'% · '.$period)
                ->color($m['revenue_change'] !== null && $m['revenue_change'] < 0 ? 'danger' : 'success'),
            Stat::make(__('shop.admin.analytics.orders'), number_format($m['orders']))->description($period),
            Stat::make(__('shop.admin.analytics.aov'), money($m['aov']))->description($period),
            Stat::make(__('shop.admin.analytics.conversion'), $m['conversion'].'%')->description(__('shop.admin.analytics.conversion_help')),
        ];
    }
}
