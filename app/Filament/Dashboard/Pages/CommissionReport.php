<?php

namespace App\Filament\Dashboard\Pages;

use App\Domain\Branches\Models\Branch;
use App\Domain\POS\Actions\BuildCommissionReport;
use App\Domain\POS\Exports\CommissionPayrollExport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/** Staff commissions & tips per period, with the payroll Excel export. */
class CommissionReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $slug = 'reports/commissions';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.dashboard.pos.commission-report';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public ?int $branchId = null;

    public static function canAccess(): bool
    {
        return (bool) tenant()?->hasModule('pos') && (auth()->user()?->can('reports.view') ?? false);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('pos.nav.commissions');
    }

    public function getTitle(): string
    {
        return __('pos.commissions.title');
    }

    public function mount(): void
    {
        $tz = tenant()?->timezone ?? config('app.timezone');
        $this->from = $this->from ?: now($tz)->startOfMonth()->toDateString();
        $this->until = $this->until ?: now($tz)->toDateString();
    }

    public function setPeriod(string $preset): void
    {
        $now = now(tenant()?->timezone ?? config('app.timezone'));
        [$from, $until] = match ($preset) {
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'last_7' => [$now->copy()->subDays(6), $now],
            default => [$now->copy()->startOfMonth(), $now],
        };
        $this->from = $from->toDateString();
        $this->until = $until->toDateString();
        unset($this->rows);
    }

    #[Computed]
    public function branches(): Collection
    {
        return Branch::query()->orderBy('sort')->get();
    }

    #[Computed]
    public function rows(): array
    {
        return app(BuildCommissionReport::class)->handle($this->fromDate(), $this->untilDate(), $this->branchId);
    }

    public function updated(): void
    {
        unset($this->rows);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label(__('pos.commissions.export'))->icon(Heroicon::OutlinedArrowDownTray)->color('primary')
                ->action(fn () => Excel::download(
                    new CommissionPayrollExport($this->rows, $this->from.' → '.$this->until, tenant()->currency ?? 'QAR'),
                    'payroll-'.$this->from.'-'.$this->until.'.xlsx',
                )),
        ];
    }

    protected function fromDate(): Carbon
    {
        return rescue(fn () => Carbon::parse($this->from), now()->startOfMonth(), false);
    }

    protected function untilDate(): Carbon
    {
        return rescue(fn () => Carbon::parse($this->until), now(), false);
    }
}
