<?php

namespace App\Filament\Dashboard\Resources\Clients\Pages;

use App\Domain\CRM\Actions\SaveClientConsents;
use App\Filament\Dashboard\Resources\Clients\ClientResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected array $consents = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data += ClientResource::consentState($this->record);
        if (ClientResource::canSeePrivate()) {
            $data['allergies'] = $this->record->allergies; // hidden attribute, decrypted only for permitted staff
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->consents = ClientResource::pullConsents($data);

        return $data;
    }

    protected function afterSave(): void
    {
        app(SaveClientConsents::class)->handle($this->record, $this->consents);
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()];
    }
}
