<?php

namespace App\Filament\Dashboard\Resources\Clients\Pages;

use App\Domain\Workspace\Excel\ClientsImport;
use App\Filament\Dashboard\Concerns\HasExcelActions;
use App\Filament\Dashboard\Resources\Clients\ClientResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListClients extends ListRecords
{
    use HasExcelActions;

    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->excelActions(ClientsImport::class, 'clients', canImport: ClientResource::canManage(), canExport: (bool) auth()->user()?->can('clients.export')),
            CreateAction::make()->label(__('workspace.clients.add'))->icon(Heroicon::OutlinedUserPlus),
        ];
    }
}
