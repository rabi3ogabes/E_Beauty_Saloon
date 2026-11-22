<?php

namespace App\Filament\Admin\Resources\MessagePrices\Pages;

use App\Filament\Admin\Resources\MessagePrices\PlatformMessageChannelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePlatformMessageChannels extends ManageRecords
{
    protected static string $resource = PlatformMessageChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()];
    }
}
