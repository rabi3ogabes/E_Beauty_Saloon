<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Billing\Actions\PlatformMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '120s';

    protected function getStats(): array
    {
        $m = app(PlatformMetrics::class)->summary();
        $spark = array_values(array_map(fn ($v) => $v / 100, $m['collected_by_month']));

        return [
            Stat::make('MRR', money($m['mrr'], 'QAR'))->description(__('admin.dashboard.arr', ['amount' => money($m['arr'], 'QAR')]))
                ->descriptionIcon('heroicon-m-arrow-trending-up')->chart($spark)->color('primary'),
            Stat::make(__('admin.dashboard.active_salons'), number_format($m['active_tenants']))
                ->description(__('admin.dashboard.trials', ['count' => $m['trials']]))->descriptionIcon('heroicon-m-sparkles'),
            Stat::make(__('admin.dashboard.churn'), $m['churn_percent'].'%')
                ->description(__('admin.dashboard.last_30'))->color($m['churn_percent'] > 5 ? 'danger' : 'success'),
            Stat::make(__('admin.dashboard.renewals'), (string) $m['renewals_week'])
                ->description(__('admin.dashboard.overdue', ['count' => $m['overdue_invoices']]))
                ->descriptionIcon('heroicon-m-exclamation-triangle')->color($m['overdue_invoices'] ? 'warning' : 'gray'),
        ];
    }
}
