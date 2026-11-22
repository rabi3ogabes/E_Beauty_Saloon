<?php

namespace App\Filament\Admin\Resources\ImpersonationLogs\Pages;

use App\Filament\Admin\Resources\ImpersonationLogs\ImpersonationLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageImpersonationLogs extends ManageRecords
{
    protected static string $resource = ImpersonationLogResource::class;

    protected function getHeaderActions(): array
    {
        return ImpersonationLogResource::canCreate() ? [CreateAction::make()] : [];
    }
}
