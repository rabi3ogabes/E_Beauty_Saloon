<?php

namespace App\Filament\Dashboard\Resources\Branches\Pages;

use App\Domain\Branches\Actions\CreateBranch;
use App\Domain\Branches\Actions\NormalizeOpeningHours;
use App\Filament\Dashboard\Resources\Branches\BranchResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['hours'] = NormalizeOpeningHours::toRows($this->record->opening_hours);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['opening_hours'] = app(NormalizeOpeningHours::class)->handle($data['hours'] ?? []);
        unset($data['hours']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->visible(fn () => BranchResource::canManage() && CreateBranch::count() > 1)];
    }
}
