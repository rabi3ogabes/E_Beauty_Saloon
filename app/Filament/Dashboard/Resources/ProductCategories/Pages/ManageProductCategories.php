<?php

namespace App\Filament\Dashboard\Resources\ProductCategories\Pages;

use App\Filament\Dashboard\Resources\ProductCategories\ProductCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageProductCategories extends ManageRecords
{
    protected static string $resource = ProductCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('workspace.product_categories.add'))->icon(Heroicon::OutlinedPlus)->slideOver()];
    }
}
