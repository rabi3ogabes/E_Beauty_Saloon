<?php

namespace App\Filament\Dashboard\Resources\Shop\StaffPicks\Pages;

use App\Filament\Dashboard\Resources\Shop\StaffPicks\StaffPickResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStaffPicks extends ManageRecords
{
    protected static string $resource = StaffPickResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()];
    }
}
