<?php

namespace App\Filament\Dashboard\Resources\Marketing\Packages\Pages;

use App\Filament\Dashboard\Resources\Marketing\Packages\PackageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePackages extends ManageRecords
{
    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()];
    }
}
