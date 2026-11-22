<?php

namespace App\Filament\Dashboard\Pages\Shop;

use App\Filament\Dashboard\Resources\Shop\ShopGate;
use App\Filament\Dashboard\Widgets\Shop\ShopRevenueChart;
use App\Filament\Dashboard\Widgets\Shop\ShopStatsOverview;
use App\Filament\Dashboard\Widgets\Shop\ShopStatusChart;
use App\Filament\Dashboard\Widgets\Shop\ShopTopProductsChart;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class ShopAnalytics extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'shop/analytics';

    public static function getNavigationGroup(): ?string
    {
        return ShopGate::group();
    }

    public static function getNavigationLabel(): string
    {
        return __('shop.admin.analytics.title');
    }

    public function getTitle(): string
    {
        return __('shop.admin.analytics.title');
    }

    public static function canAccess(): bool
    {
        return ShopGate::view();
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ShopStatsOverview::class,
            ShopRevenueChart::class,
            ShopTopProductsChart::class,
            ShopStatusChart::make(['groupBy' => 'status']),
            ShopStatusChart::make(['groupBy' => 'fulfilment']),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }
}
