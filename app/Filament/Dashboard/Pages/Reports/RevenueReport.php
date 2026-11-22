<?php

namespace App\Filament\Dashboard\Pages\Reports;

use App\Domain\Insights\Support\InsightsGate;
use App\Filament\Dashboard\Support\ReportPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;

/** Revenue by branch, staff, service and product; average ticket and client lifetime value. Needs reports.revenue. */
class RevenueReport extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $slug = 'reports/revenue';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.dashboard.insights.revenue-report';

    public static function canAccess(): bool
    {
        return InsightsGate::revenue();
    }

    public static function getNavigationLabel(): string
    {
        return __('insights.reports.revenue.nav');
    }

    public function getTitle(): string
    {
        return __('insights.reports.revenue.title');
    }

    #[Computed]
    public function data(): array
    {
        $r = $this->reports();
        $p = $this->period();
        $f = $this->filters();
        $total = $r->revenueTotal($p, $f);
        $previous = $r->revenueTotal($p->previous(), $f);

        return [
            'total' => $total,
            'previous' => $previous,
            'change' => $previous ? round(($total - $previous) / $previous * 100, 1) : null,
            'ticket' => $r->averageTicket($p, $f),
            'ltv' => $r->ltv($f),
            'daily' => $r->revenueDaily($p, $f),
            'branches' => $r->revenueByBranch($p, $f),
            'staff' => $r->revenueByStaff($p, $f),
            'services' => $r->revenueByService($p, $f),
            'products' => $r->revenueByProduct($p, $f),
        ];
    }

    public function exportSections(): array
    {
        $d = $this->data;
        $t = fn (string $k) => __('insights.reports.cols.'.$k);

        return [
            ['title' => __('insights.reports.revenue.by_branch'), 'headings' => [$t('branch'), $t('pos'), $t('shop'), $t('total'), $t('tickets'), $t('average_ticket')], 'money' => [1, 2, 3, 5],
                'rows' => array_map(fn ($r) => [$r['branch'], $r['pos'], $r['shop'], $r['total'], $r['tickets'], $r['average_ticket']], $d['branches'])],
            ['title' => __('insights.reports.revenue.by_staff'), 'headings' => [$t('staff'), $t('services'), $t('products'), $t('total'), $t('items')], 'money' => [1, 2, 3],
                'rows' => array_map(fn ($r) => [$r['staff'], $r['services'], $r['products'], $r['total'], $r['items']], $d['staff'])],
            ['title' => __('insights.reports.revenue.by_service'), 'headings' => [$t('service'), $t('category'), $t('count'), $t('total')], 'money' => [3],
                'rows' => array_map(fn ($r) => [$r['service'], $r['category'], $r['count'], $r['total']], $d['services'])],
            ['title' => __('insights.reports.revenue.by_product'), 'headings' => [$t('product'), $t('units'), $t('pos'), $t('shop'), $t('total')], 'money' => [2, 3, 4],
                'rows' => array_map(fn ($r) => [$r['product'], $r['units'], $r['pos'], $r['shop'], $r['total']], $d['products'])],
            ['title' => __('insights.reports.revenue.daily'), 'headings' => [$t('date'), $t('total')], 'money' => [1],
                'rows' => array_map(fn ($date, $v) => [$date, $v], array_keys($d['daily']), array_values($d['daily']))],
        ];
    }
}
