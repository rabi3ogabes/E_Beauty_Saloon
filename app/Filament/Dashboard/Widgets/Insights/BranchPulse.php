<?php

namespace App\Filament\Dashboard\Widgets\Insights;

use App\Domain\Insights\CommandCenter\TodaySnapshot;
use App\Domain\Insights\Support\InsightsGate;
use Filament\Widgets\Widget;

/** Today per branch: bookings, utilization, revenue vs target, staff on shift, low stock, open orders. */
class BranchPulse extends Widget
{
    protected static ?int $sort = -1;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.dashboard.insights.branch-pulse';

    public static function canView(): bool
    {
        return InsightsGate::reports();
    }

    protected function getViewData(): array
    {
        return [
            'rows' => app(TodaySnapshot::class)->branches(),
            'showRevenue' => InsightsGate::revenue(),
            'showOrders' => (bool) tenant()?->hasModule('shop'),
        ];
    }
}
