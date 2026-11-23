<?php

namespace App\Filament\Dashboard\Resources\Suppliers\Pages;

use App\Filament\Dashboard\Resources\Suppliers\SupplierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageSuppliers extends ManageRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('workspace.suppliers.add'))->icon(Heroicon::OutlinedPlus)->slideOver()];
    }
}
