<?php

namespace App\Filament\Admin\Resources\AICreditPacks\Pages;

use App\Filament\Admin\Resources\AICreditPacks\AICreditPackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAICreditPacks extends ManageRecords
{
    protected static string $resource = AICreditPackResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
