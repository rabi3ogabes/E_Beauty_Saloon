<?php

namespace App\Filament\Dashboard\Resources\Staff\Pages;

use App\Domain\Team\Actions\SyncStaffSkills;
use App\Filament\Dashboard\Resources\Staff\StaffResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    protected array $skills = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->skills = $data['skills'] ?? [];
        unset($data['skills']);

        return $data;
    }

    protected function afterCreate(): void
    {
        app(SyncStaffSkills::class)->handle($this->record, $this->skills);
    }

    protected function getRedirectUrl(): string
    {
        return StaffResource::getUrl('edit', ['record' => $this->record]);
    }
}
