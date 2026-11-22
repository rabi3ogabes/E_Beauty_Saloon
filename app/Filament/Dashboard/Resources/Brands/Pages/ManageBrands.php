<?php

namespace App\Filament\Dashboard\Resources\Brands\Pages;

use App\Filament\Dashboard\Resources\Brands\BrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageBrands extends ManageRecords
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('workspace.brands.add'))->icon(Heroicon::OutlinedPlus)->slideOver()];
    }
}
