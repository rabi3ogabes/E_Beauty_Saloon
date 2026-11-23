<?php

namespace App\Filament\Dashboard\Resources\Services\Pages;

use App\Filament\Dashboard\Resources\Services\ServiceResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ServiceResource::unpackDeposit($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ServiceResource::packDeposit($data);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), RestoreAction::make()];
    }
}
