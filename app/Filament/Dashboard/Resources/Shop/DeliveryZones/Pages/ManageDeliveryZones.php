<?php

namespace App\Filament\Dashboard\Resources\Shop\DeliveryZones\Pages;

use App\Filament\Dashboard\Resources\Shop\DeliveryZones\DeliveryZoneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDeliveryZones extends ManageRecords
{
    protected static string $resource = DeliveryZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()];
    }
}
