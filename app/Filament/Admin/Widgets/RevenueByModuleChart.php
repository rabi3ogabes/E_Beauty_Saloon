<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Billing\Actions\PlatformMetrics;
use Filament\Widgets\ChartWidget;

class RevenueByModuleChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $maxHeight = '280px';

    public function getHeading(): ?string
    {
        return __('admin.dashboard.revenue_by_module');
    }

    protected function getData(): array
    {
        $data = app(PlatformMetrics::class)->summary()['revenue_by_module'];
        arsort($data);
        $labels = array_map(fn ($k) => in_array($k, ['plan', 'branches'], true) ? __('admin.dashboard.'.$k) : __('salon.modules.'.$k), array_keys($data));

        return [
            'datasets' => [[
                'data' => array_map(fn ($v) => round($v / 100, 2), array_values($data)),
                'backgroundColor' => ['#C8A96A', '#E9D6CF', '#1C1A17', '#A88B4F', '#4F7A5A', '#8E7DBE', '#C48A3A', '#6B7FA3', '#D9A5B3'],
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
        return ['plugins' => ['legend' => ['position' => 'bottom']], 'cutout' => '68%'];
    }
}
