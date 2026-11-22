<?php

namespace App\Filament\Dashboard\Widgets\Shop;

use App\Domain\Shop\Support\ShopMetrics;
use App\Filament\Dashboard\Resources\Shop\ShopGate;
use Filament\Widgets\ChartWidget;

class ShopTopProductsChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 3;

    protected ?string $maxHeight = '300px';

    public static function canView(): bool
    {
        return ShopGate::view();
    }

    public function getHeading(): ?string
    {
        return __('shop.admin.analytics.top_products');
    }

    protected function getData(): array
    {
        $data = app(ShopMetrics::class)->topProducts();

        return [
            'datasets' => [[
                'label' => __('shop.admin.analytics.top_products'),
                'data' => array_values($data),
                'backgroundColor' => collect(array_keys($data))->map(fn ($k, $i) => ShopMetrics::PALETTE[$i % 5])->all(),
                'borderRadius' => 6,
            ]],
            'labels' => array_map(fn ($l) => mb_strimwidth($l, 0, 24, '…'), array_keys($data)),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return ['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]], 'scales' => ['x' => ['grid' => ['color' => 'rgba(0,0,0,0.04)']], 'y' => ['grid' => ['display' => false]]]];
    }
}
