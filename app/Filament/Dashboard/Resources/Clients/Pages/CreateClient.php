<?php

namespace App\Filament\Dashboard\Resources\Clients\Pages;

use App\Domain\CRM\Actions\SaveClientConsents;
use App\Filament\Dashboard\Resources\Clients\ClientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    protected array $consents = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->consents = ClientResource::pullConsents($data);

        return $data;
    }

    protected function afterCreate(): void
    {
        app(SaveClientConsents::class)->handle($this->record, $this->consents);
    }

    protected function getRedirectUrl(): string
    {
        return ClientResource::getUrl('view', ['record' => $this->record]);
    }
}
