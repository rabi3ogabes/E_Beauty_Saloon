<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Billing\Actions\PlatformMetrics;
use Filament\Widgets\ChartWidget;

class CollectedRevenueChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $maxHeight = '280px';

    public function getHeading(): ?string
    {
        return __('admin.dashboard.collected');
    }

    protected function getData(): array
    {
        $data = app(PlatformMetrics::class)->summary()['collected_by_month'];

        return [
            'datasets' => [[
                'label' => 'QAR',
                'data' => array_map(fn ($v) => round($v / 100, 2), array_values($data)),
                'borderColor' => '#C8A96A',
                'backgroundColor' => 'rgba(200,169,106,0.12)',
                'fill' => true,
                'tension' => 0.35,
                'pointRadius' => 0,
            ]],
            'labels' => array_keys($data),
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
