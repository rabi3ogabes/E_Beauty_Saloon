<?php

namespace App\Filament\Dashboard\Resources\Products\Pages;

use App\Filament\Dashboard\Resources\Products\ProductResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ProductResource::prepareSlug($data);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
