<?php

namespace App\Filament\Admin\Resources\Leads\Pages;

use App\Filament\Admin\Resources\Leads\LeadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLeads extends ManageRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return LeadResource::canCreate() ? [CreateAction::make()] : [];
    }
}
