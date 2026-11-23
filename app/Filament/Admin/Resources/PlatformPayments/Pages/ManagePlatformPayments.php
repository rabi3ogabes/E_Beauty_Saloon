<?php

namespace App\Filament\Admin\Resources\PlatformPayments\Pages;

use App\Filament\Admin\Resources\PlatformPayments\PlatformPaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePlatformPayments extends ManageRecords
{
    protected static string $resource = PlatformPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return PlatformPaymentResource::canCreate() ? [CreateAction::make()] : [];
    }
}
