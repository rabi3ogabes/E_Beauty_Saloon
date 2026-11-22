<?php

namespace App\Filament\Dashboard\Resources\PosRegisters\Pages;

use App\Filament\Dashboard\Resources\PosRegisters\RegisterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRegisters extends ManageRecords
{
    protected static string $resource = RegisterResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()];
    }
}
