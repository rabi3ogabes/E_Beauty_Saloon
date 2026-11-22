<?php

namespace App\Filament\Dashboard\Widgets\Insights;

use App\Domain\Insights\CommandCenter\TodaySnapshot;
use App\Domain\Insights\Support\InsightsGate;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Owner command center: today's KPIs (all visible branches, honours the branch switcher) with 14-day sparklines. */
class CommandCenterKpis extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return InsightsGate::reports();
    }

    protected function getColumns(): int|array
    {
        return ['default' => 2, 'md' => 3, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $t = app(TodaySnapshot::class)->totals();
        $stats = [
            Stat::make(__('insights.kpi.bookings'), number_format($t['bookings']))
                ->description(__('insights.kpi.bookings_hint'))->icon(Heroicon::OutlinedCalendarDays)
                ->chart($t['spark']['bookings'])->chartColor('primary')->color('primary'),
            Stat::make(__('insights.kpi.utilization'), $t['utilization'].'%')
                ->description(__('insights.kpi.utilization_hint'))->icon(Heroicon::OutlinedChartPie)
                ->chart($t['spark']['utilization'])->chartColor($t['utilization'] >= 60 ? 'success' : 'warning')
                ->color($t['utilization'] >= 60 ? 'success' : 'warning'),
        ];
        if (InsightsGate::revenue()) {
            $hit = $t['target_progress'] !== null && $t['target_progress'] >= 100;
            $stats[] = Stat::make(__('insights.kpi.revenue'), money($t['revenue']))
                ->description($t['target'] > 0 ? __('insights.kpi.revenue_vs_target', ['pct' => $t['target_progress'], 'target' => money($t['target'])]) : __('insights.kpi.no_target'))
                ->descriptionIcon($hit ? Heroicon::ArrowTrendingUp : Heroicon::OutlinedFlag)
                ->icon(Heroicon::OutlinedBanknotes)
                ->chart($t['spark']['revenue'])->chartColor('primary')
                ->color($hit ? 'success' : 'primary');
        }
        $stats[] = Stat::make(__('insights.kpi.staff_on_shift'), (string) $t['staff_on_shift'])
            ->description(__('insights.kpi.staff_hint'))->icon(Heroicon::OutlinedUserGroup);
        $stats[] = Stat::make(__('insights.kpi.low_stock'), (string) $t['low_stock'])
            ->description($t['low_stock'] ? __('insights.kpi.low_stock_hint') : __('insights.kpi.all_good'))
            ->icon(Heroicon::OutlinedArchiveBox)->color($t['low_stock'] ? 'danger' : 'success');
        if (tenant()?->hasModule('shop')) {
            $stats[] = Stat::make(__('insights.kpi.pending_orders'), (string) $t['pending_orders'])
                ->description(__('insights.kpi.pending_orders_hint'))->icon(Heroicon::OutlinedShoppingBag)
                ->color($t['pending_orders'] ? 'warning' : 'gray');
        }
        $stats[] = Stat::make(__('insights.kpi.unread_messages'), (string) $t['unread_messages'])
            ->description(__('insights.kpi.unread_hint'))->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->color($t['unread_messages'] ? 'warning' : 'gray');

        return $stats;
    }
}
