<?php

namespace App\Filament\Dashboard\Resources\Products\Pages;

use App\Domain\Workspace\Excel\ProductsImport;
use App\Filament\Dashboard\Concerns\HasExcelActions;
use App\Filament\Dashboard\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListProducts extends ListRecords
{
    use HasExcelActions;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->excelActions(ProductsImport::class, 'products', canImport: ProductResource::canManage()),
            CreateAction::make()->label(__('workspace.products.add'))->icon(Heroicon::OutlinedPlus),
        ];
    }
}
