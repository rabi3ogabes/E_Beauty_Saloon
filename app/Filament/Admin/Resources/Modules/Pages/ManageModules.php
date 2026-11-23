<?php

namespace App\Filament\Admin\Resources\Modules\Pages;

use App\Filament\Admin\Resources\Modules\ModuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageModules extends ManageRecords
{
    protected static string $resource = ModuleResource::class;

    protected function getHeaderActions(): array
    {
        return ModuleResource::canCreate() ? [CreateAction::make()] : [];
    }
}
