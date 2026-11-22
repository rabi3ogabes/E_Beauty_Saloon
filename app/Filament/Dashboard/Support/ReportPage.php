<?php

namespace App\Filament\Dashboard\Support;

use App\Domain\Branches\Models\Branch;
use App\Domain\Insights\Exports\ReportExport;
use App\Domain\Insights\Reports\ReportService;
use App\Domain\Insights\Support\InsightsGate;
use App\Domain\Insights\Support\Period;
use App\Support\Money\Money;
use App\Support\Pdf\PdfRenderer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Base for report pages (navigation group "Reports"): period + branch filters bound to the URL, Excel and PDF export
 * of the page's sections. Subclasses implement exportSections() and render their own Blade view.
 *
 * A section: ['title' => string, 'headings' => list<string>, 'rows' => list<list<scalar>>, 'money' => list<int> column indexes].
 */
abstract class ReportPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public ?int $branchId = null;

    abstract public function exportSections(): array;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.reports');
    }

    public static function canAccess(): bool
    {
        return InsightsGate::reports();
    }

    public function mount(): void
    {
        $default = Period::preset('last_30');
        $this->from = $this->from ?: $default->from->toDateString();
        $this->until = $this->until ?: $default->until->toDateString();
    }

    public function period(): Period
    {
        return rescue(fn () => Period::between($this->from, $this->until), Period::preset('last_30'), false);
    }

    public function filters(): array
    {
        return array_filter(['branch_id' => $this->branchId]);
    }

    public function reports(): ReportService
    {
        return app(ReportService::class);
    }

    public function setPeriod(string $preset): void
    {
        $p = Period::preset($preset);
        $this->from = $p->from->toDateString();
        $this->until = $p->until->toDateString();
        $this->resetComputed();
    }

    public function updated(): void
    {
        $this->resetComputed();
    }

    protected function resetComputed(): void
    {
        unset($this->data);
    }

    #[Computed]
    public function branches(): Collection
    {
        return Branch::query()->orderBy('sort')->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('exportExcel')->label(__('insights.reports.export_excel'))->icon(Heroicon::OutlinedTableCells)
                    ->action(fn () => Excel::download(new ReportExport($this->formattedSections('excel')), $this->exportFileName().'.xlsx')),
                Action::make('exportPdf')->label(__('insights.reports.export_pdf'))->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->action(function () {
                        $pdf = app(PdfRenderer::class)->render('pdf.insights.report', [
                            'title' => $this->getTitle(),
                            'tenant' => tenant(),
                            'period' => $this->period()->label(),
                            'branch' => $this->branchId ? (string) $this->branches->firstWhere('id', $this->branchId)?->name : null,
                            'sections' => $this->formattedSections('pdf'),
                        ], format: 'A4-L');

                        return response()->streamDownload(fn () => print ($pdf), $this->exportFileName().'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
            ])->label(__('insights.reports.export'))->icon(Heroicon::OutlinedArrowDownTray)->button()->color('gray')
                ->visible(fn () => InsightsGate::export()),
        ];
    }

    public function formattedSections(string $format): array
    {
        $currency = tenant()?->currency ?? 'QAR';

        return array_map(function (array $section) use ($format, $currency) {
            $money = $section['money'] ?? [];
            $section['rows'] = array_map(function (array $row) use ($money, $format, $currency) {
                $row = array_values($row);
                foreach ($money as $i) {
                    if (isset($row[$i]) && is_int($row[$i])) {
                        $row[$i] = $format === 'excel' ? (float) Money::of($row[$i], $currency)->toDecimal() : money($row[$i]);
                    }
                }

                return array_map(fn ($v) => $v ?? '—', $row);
            }, $section['rows']);
            if ($format === 'excel') {
                foreach ($money as $i) {
                    if (isset($section['headings'][$i])) {
                        $section['headings'][$i] .= ' ('.$currency.')';
                    }
                }
            }

            return $section;
        }, $this->exportSections());
    }

    protected function exportFileName(): string
    {
        return str(static::getSlug())->afterLast('/')->slug().'-'.$this->period()->from->toDateString().'-'.$this->period()->until->toDateString();
    }
}
