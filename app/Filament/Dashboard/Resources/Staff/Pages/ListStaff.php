<?php

namespace App\Filament\Dashboard\Resources\Staff\Pages;

use App\Domain\Workspace\Excel\StaffImport;
use App\Filament\Dashboard\Concerns\HasExcelActions;
use App\Filament\Dashboard\Resources\Staff\StaffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListStaff extends ListRecords
{
    use HasExcelActions;

    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->excelActions(StaffImport::class, 'staff', canImport: StaffResource::canManage()),
            CreateAction::make()->label(__('workspace.staff.add'))->icon(Heroicon::OutlinedPlus),
        ];
    }
}
