<?php

namespace App\Filament\Dashboard\Resources\Staff\Pages;

use App\Domain\Team\Actions\SyncStaffSkills;
use App\Filament\Dashboard\Resources\Staff\StaffResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    protected array $skills = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['skills'] = StaffResource::skillRows($this->record);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->skills = $data['skills'] ?? [];
        unset($data['skills']);

        return $data;
    }

    protected function afterSave(): void
    {
        app(SyncStaffSkills::class)->handle($this->record, $this->skills);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
