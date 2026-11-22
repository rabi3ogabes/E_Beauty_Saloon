<?php

namespace App\Filament\Dashboard\Resources\Services\Pages;

use App\Filament\Dashboard\Resources\Services\ServiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ServiceResource::packDeposit($data);
    }

    protected function getRedirectUrl(): string
    {
        return ServiceResource::getUrl('edit', ['record' => $this->record]);
    }
}
