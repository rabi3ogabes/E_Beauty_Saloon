<?php

namespace App\Filament\Dashboard\Widgets\Shop;

use App\Domain\Shop\Support\ShopMetrics;
use App\Filament\Dashboard\Resources\Shop\ShopGate;
use Filament\Widgets\ChartWidget;

class ShopRevenueChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return ShopGate::view();
    }

    public function getHeading(): ?string
    {
        return __('shop.admin.analytics.revenue_chart');
    }

    protected function getData(): array
    {
        $data = app(ShopMetrics::class)->revenueByDay();

        return [
            'datasets' => [[
                'label' => tenant()?->currency ?? 'QAR',
                'data' => array_map(fn ($v) => round($v / 100, 2), array_values($data)),
                'borderColor' => ShopMetrics::PALETTE[0],
                'backgroundColor' => 'rgba(200,169,106,0.14)',
                'fill' => true,
                'tension' => 0.35,
                'pointRadius' => 0,
            ]],
            'labels' => array_map(fn ($d) => substr($d, 5), array_keys($data)),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['display' => false]], 'scales' => ['y' => ['grid' => ['color' => 'rgba(0,0,0,0.04)']], 'x' => ['grid' => ['display' => false]]]];
    }
}
