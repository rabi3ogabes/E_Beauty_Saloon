<?php

namespace App\Filament\Dashboard\Pages\Reports;

use App\Filament\Dashboard\Support\ReportPage;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;

/** Utilization heatmap (day × hour), no-show and rebook rates, bookings, new clients and retention cohorts. */
class PerformanceReport extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?string $slug = 'reports/performance';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.dashboard.insights.performance-report';

    public static function getNavigationLabel(): string
    {
        return __('insights.reports.performance.nav');
    }

    public function getTitle(): string
    {
        return __('insights.reports.performance.title');
    }

    #[Computed]
    public function data(): array
    {
        $r = $this->reports();
        $p = $this->period();
        $f = $this->filters();

        return [
            'utilization' => $r->utilization($p, $f),
            'heatmap' => $r->utilizationHeatmap($p, $f),
            'no_show' => $r->noShowRate($p, $f),
            'rebook' => $r->rebookRate($p, $f),
            'bookings' => $r->bookingsCount($p, $f),
            'new_clients' => $r->newClients($p),
            'cohorts' => $r->retentionCohorts(6, $f),
        ];
    }

    public static function weekdayName(int $wd): string
    {
        return CarbonImmutable::now()->startOfWeek(CarbonImmutable::SUNDAY)->addDays($wd)->translatedFormat('D');
    }

    public function exportSections(): array
    {
        $d = $this->data;
        $t = fn (string $k) => __('insights.reports.cols.'.$k);
        $hours = $d['heatmap']['hours'];
        $heat = [];
        foreach ([6, 0, 1, 2, 3, 4, 5] as $wd) {
            $heat[] = array_merge([self::weekdayName($wd)], array_map(fn ($h) => $d['heatmap']['matrix'][$wd][$h] === null ? '—' : $d['heatmap']['matrix'][$wd][$h].'%', $hours));
        }
        $maxRetention = max(1, ...array_map(fn ($c) => count($c['retention']), $d['cohorts'] ?: [['retention' => []]]));

        return [
            ['title' => __('insights.reports.performance.summary'), 'headings' => [$t('metric'), $t('value')], 'rows' => [
                [__('insights.reports.performance.utilization'), $d['utilization']['rate'].'%'],
                [__('insights.reports.performance.bookings'), $d['bookings']],
                [__('insights.reports.performance.no_show_rate'), $d['no_show']['rate'].'% ('.$d['no_show']['no_shows'].')'],
                [__('insights.reports.performance.rebook_rate'), $d['rebook']['rate'].'% ('.$d['rebook']['rebooked'].'/'.$d['rebook']['visits'].')'],
                [__('insights.reports.performance.new_clients'), $d['new_clients']],
            ]],
            ['title' => __('insights.reports.performance.heatmap'), 'headings' => array_merge([$t('day')], array_map(fn ($h) => sprintf('%02d:00', $h), $hours)), 'rows' => $heat],
            ['title' => __('insights.reports.performance.cohorts'), 'headings' => array_merge([$t('cohort'), $t('clients')], array_map(fn ($k) => '+'.$k, range(1, $maxRetention))),
                'rows' => array_map(fn ($c) => array_pad(array_merge([$c['cohort'], $c['size']], array_map(fn ($v) => $v === null ? '—' : $v.'%', $c['retention'])), $maxRetention + 2, ''), $d['cohorts'])],
        ];
    }
}
