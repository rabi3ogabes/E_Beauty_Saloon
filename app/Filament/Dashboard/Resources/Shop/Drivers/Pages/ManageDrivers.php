<?php

namespace App\Filament\Dashboard\Resources\Shop\Drivers\Pages;

use App\Filament\Dashboard\Resources\Shop\Drivers\DriverResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDrivers extends ManageRecords
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()];
    }
}
