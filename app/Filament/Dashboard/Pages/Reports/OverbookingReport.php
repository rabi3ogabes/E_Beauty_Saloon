<?php

namespace App\Filament\Dashboard\Pages\Reports;

use App\Domain\Insights\Support\Period;
use App\Filament\Dashboard\Support\ReportPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;

/**
 * Overbooking protection, the safety net: the booking engine already refuses double bookings, this detector finds
 * conflicts that slipped in through manual overrides, imports or offline edits (same staff, overlapping services).
 */
class OverbookingReport extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $slug = 'reports/overbookings';

    protected static ?int $navigationSort = 14;

    protected string $view = 'filament.dashboard.insights.table-report';

    public static function canAccess(): bool
    {
        return parent::canAccess() && (bool) tenant()?->hasModule('booking');
    }

    public static function getNavigationLabel(): string
    {
        return __('insights.overbookings.nav');
    }

    public function getTitle(): string
    {
        return __('insights.overbookings.title');
    }

    public function getSubheading(): ?string
    {
        return __('insights.overbookings.subtitle');
    }

    public function mount(): void
    {
        // Default window: the next 30 days, where a conflict can still be fixed.
        $tz = Period::tz();
        $this->from = $this->from ?: now($tz)->toDateString();
        $this->until = $this->until ?: now($tz)->addDays(30)->toDateString();
    }

    #[Computed]
    public function data(): array
    {
        $rows = $this->reports()->overbookings($this->period(), $this->filters());

        return ['rows' => $rows, 'empty' => __('insights.overbookings.empty'), 'kpis' => [
            [__('insights.overbookings.conflicts'), (string) count($rows)],
            [__('insights.overbookings.staff'), (string) collect($rows)->pluck('staff_id')->unique()->count()],
        ]];
    }

    public function exportSections(): array
    {
        $t = fn (string $k) => __('insights.reports.cols.'.$k);

        return [[
            'title' => __('insights.overbookings.title'),
            'headings' => [$t('staff'), $t('branch'), $t('starts_at'), $t('booking_a'), $t('booking_b'), $t('overlap')],
            'rows' => array_map(fn ($r) => [$r['staff'], $r['branch'], $r['starts_at'], $r['a'], $r['b'], $r['overlap_minutes']], $this->data['rows']),
        ]];
    }
}
