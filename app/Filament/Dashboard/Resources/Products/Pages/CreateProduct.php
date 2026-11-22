<?php

namespace App\Filament\Dashboard\Resources\Products\Pages;

use App\Filament\Dashboard\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ProductResource::prepareSlug($data);
    }

    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('edit', ['record' => $this->record]);
    }
}
