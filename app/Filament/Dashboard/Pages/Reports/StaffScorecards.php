<?php

namespace App\Filament\Dashboard\Pages\Reports;

use App\Domain\Insights\Support\InsightsGate;
use App\Filament\Dashboard\Support\ReportPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;

/** Staff performance scorecards. Revenue columns only for users with reports.revenue. */
class StaffScorecards extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?string $slug = 'reports/scorecards';

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.dashboard.insights.table-report';

    public static function getNavigationLabel(): string
    {
        return __('insights.reports.scorecards.nav');
    }

    public function getTitle(): string
    {
        return __('insights.reports.scorecards.title');
    }

    public function getSubheading(): ?string
    {
        return __('insights.reports.scorecards.subtitle');
    }

    #[Computed]
    public function data(): array
    {
        $rows = $this->reports()->staffScorecards($this->period(), $this->filters());
        $top = $rows[0] ?? null;

        return ['rows' => $rows, 'kpis' => array_values(array_filter([
            [__('insights.reports.scorecards.team'), (string) count($rows)],
            InsightsGate::revenue() && $top ? [__('insights.reports.scorecards.top'), $top['staff']] : null,
            [__('insights.reports.scorecards.avg_util'), $rows ? round(array_sum(array_column($rows, 'utilization')) / count($rows), 1).'%' : '—'],
            [__('insights.reports.scorecards.avg_rebook'), $rows ? round(array_sum(array_column($rows, 'rebook_rate')) / count($rows), 1).'%' : '—'],
        ]))];
    }

    public function exportSections(): array
    {
        $t = fn (string $k) => __('insights.reports.cols.'.$k);
        $money = InsightsGate::revenue();
        $headings = array_merge([$t('staff')], $money ? [$t('revenue'), $t('retail_attach')] : [], [$t('visits'), $t('utilization'), $t('rebook_rate'), $t('no_shows'), $t('rating')]);

        return [[
            'title' => __('insights.reports.scorecards.title'),
            'headings' => $headings,
            'money' => $money ? [1] : [],
            'rows' => array_map(fn ($r) => array_merge([$r['staff']], $money ? [$r['revenue'], $r['retail_attach'].'%'] : [],
                [$r['visits'], $r['utilization'].'%', $r['rebook_rate'].'%', $r['no_shows'], $r['rating'] === null ? null : $r['rating'].' ★ ('.$r['reviews'].')']), $this->data['rows']),
        ]];
    }
}
