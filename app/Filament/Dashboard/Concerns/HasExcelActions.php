<?php

namespace App\Filament\Dashboard\Concerns;

use App\Domain\Workspace\Excel\SheetExport;
use App\Domain\Workspace\Excel\WorkspaceImport;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Maatwebsite\Excel\Facades\Excel;

/** Bulk import / export (Excel) header actions for list pages. */
trait HasExcelActions
{
    /** @param  class-string<WorkspaceImport>  $import */
    protected function excelActions(string $import, string $filename, bool $canImport = true, bool $canExport = true): ActionGroup
    {
        return ActionGroup::make([
            Action::make('importExcel')
                ->label(__('workspace.excel.import'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->visible($canImport && ! (tenant()?->isReadOnly() ?? true))
                ->modalHeading(__('workspace.excel.import_heading'))
                ->modalDescription(__('workspace.excel.import_help', ['columns' => implode(', ', $import::columns())]))
                ->modalSubmitActionLabel(__('workspace.excel.import'))
                ->schema([
                    FileUpload::make('file')->label(__('workspace.excel.file'))->disk('local')->directory('imports')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'text/csv', 'text/plain'])
                        ->maxSize(5120)->required(),
                ])
                ->action(function (array $data) use ($import) {
                    $job = new $import;
                    Excel::import($job, $data['file'], 'local');
                    Storage::disk('local')->delete($data['file']);
                    static::notifyImportResult($job);
                }),
            Action::make('exportExcel')
                ->label(__('workspace.excel.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible($canExport)
                ->action(fn () => Excel::download(SheetExport::of($import), $filename.'-'.now()->format('Ymd').'.xlsx')),
            Action::make('downloadTemplate')
                ->label(__('workspace.excel.template'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->action(fn () => Excel::download(SheetExport::template($import), $filename.'-template.xlsx')),
        ])->label(__('workspace.excel.menu'))->icon(Heroicon::OutlinedTableCells)->button()->color('gray');
    }

    public static function notifyImportResult(WorkspaceImport $job): void
    {
        $title = __('workspace.excel.result', ['created' => $job->created, 'updated' => $job->updated]);

        if ($job->errors === []) {
            Notification::make()->success()->title($title)->send();

            return;
        }

        $lines = collect($job->errors)->take(15)
            ->map(fn ($messages, $row) => '<li><strong>'.e(__('workspace.excel.row', ['row' => $row])).'</strong> — '.e(implode(' ', $messages)).'</li>')
            ->join('');
        $more = count($job->errors) > 15 ? '<p>'.e(__('workspace.excel.more_errors', ['count' => count($job->errors) - 15])).'</p>' : '';

        Notification::make()->warning()->persistent()->title($title)
            ->body(new HtmlString('<p>'.e(__('workspace.excel.errors_intro', ['count' => count($job->errors)])).'</p><ul class="mt-1 list-disc ps-4">'.$lines.'</ul>'.$more))
            ->send();
    }
}
