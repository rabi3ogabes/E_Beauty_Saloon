<?php

namespace App\Filament\Dashboard\Resources\Services\Pages;

use App\Domain\Workspace\Excel\ServicesImport;
use App\Filament\Dashboard\Concerns\HasExcelActions;
use App\Filament\Dashboard\Resources\Services\ServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListServices extends ListRecords
{
    use HasExcelActions;

    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->excelActions(ServicesImport::class, 'services', canImport: ServiceResource::canManage()),
            CreateAction::make()->label(__('workspace.services.add'))->icon(Heroicon::OutlinedPlus),
        ];
    }
}
