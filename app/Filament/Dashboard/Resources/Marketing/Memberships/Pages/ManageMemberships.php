<?php

namespace App\Filament\Dashboard\Resources\Marketing\Memberships\Pages;

use App\Filament\Dashboard\Resources\Marketing\Memberships\MembershipResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMemberships extends ManageRecords
{
    protected static string $resource = MembershipResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()];
    }
}
