<?php

namespace App\Filament\Dashboard\Pages\Reports;

use App\Domain\Insights\Support\InsightsGate;
use App\Filament\Dashboard\Support\ReportPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;

/** Campaign ROI: attributed booking revenue vs message cost for campaigns started in the period. */
class CampaignRoiReport extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $slug = 'reports/campaigns';

    protected static ?int $navigationSort = 13;

    protected string $view = 'filament.dashboard.insights.table-report';

    public static function canAccess(): bool
    {
        return InsightsGate::revenue() && (bool) tenant()?->hasModule('marketing');
    }

    public static function getNavigationLabel(): string
    {
        return __('insights.reports.campaigns.nav');
    }

    public function getTitle(): string
    {
        return __('insights.reports.campaigns.title');
    }

    public function getSubheading(): ?string
    {
        return __('insights.reports.campaigns.subtitle');
    }

    public function hasBranchFilter(): bool
    {
        return false;
    }

    #[Computed]
    public function data(): array
    {
        $rows = $this->reports()->campaignRoi($this->period());
        $revenue = array_sum(array_column($rows, 'revenue'));
        $cost = array_sum(array_column($rows, 'cost'));

        return ['rows' => $rows, 'kpis' => [
            [__('insights.reports.campaigns.revenue'), money($revenue)],
            [__('insights.reports.campaigns.cost'), money($cost)],
            [__('insights.reports.campaigns.roi'), $cost ? round($revenue / $cost, 1).'×' : '—'],
            [__('insights.reports.campaigns.booked'), number_format(array_sum(array_column($rows, 'booked')))],
        ]];
    }

    public function exportSections(): array
    {
        $t = fn (string $k) => __('insights.reports.cols.'.$k);

        return [[
            'title' => __('insights.reports.campaigns.title'),
            'headings' => [$t('campaign'), $t('sent'), $t('booked'), $t('revenue'), $t('cost'), $t('roi')],
            'money' => [3, 4],
            'rows' => array_map(fn ($r) => [$r['name'], $r['sent'], $r['booked'], $r['revenue'], $r['cost'], $r['roi'] === null ? null : $r['roi'].'×'], $this->data['rows']),
        ]];
    }
}
