<?php

namespace App\Filament\Dashboard\Widgets\Shop;

use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Support\ShopMetrics;
use App\Filament\Dashboard\Resources\Shop\ShopGate;
use Filament\Widgets\ChartWidget;

/** Orders by status, or by fulfilment when $groupBy = 'fulfilment'. */
class ShopStatusChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 4;

    protected ?string $maxHeight = '300px';

    public string $groupBy = 'status';

    public static function canView(): bool
    {
        return ShopGate::view();
    }

    public function getHeading(): ?string
    {
        return $this->groupBy === 'fulfilment' ? __('shop.admin.analytics.by_fulfilment') : __('shop.admin.analytics.by_status');
    }

    protected function getData(): array
    {
        $data = app(ShopMetrics::class)->countBy($this->groupBy === 'fulfilment' ? 'fulfilment' : 'status');
        $labels = array_map(fn ($k) => $this->groupBy === 'fulfilment' ? __('shop.fulfilment.'.$k) : (OrderStatus::tryFrom($k)?->label() ?? $k), array_keys($data));
        $colors = array_merge(ShopMetrics::PALETTE, ['#C48A3A', '#A4473F', '#6B7FA3']);

        return [
            'datasets' => [[
                'data' => array_values($data),
                'backgroundColor' => array_slice($colors, 0, max(1, count($data))),
                'borderWidth' => 0,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return ['cutout' => '62%', 'plugins' => ['legend' => ['position' => 'bottom']], 'scales' => ['x' => ['display' => false], 'y' => ['display' => false]]];
    }
}
