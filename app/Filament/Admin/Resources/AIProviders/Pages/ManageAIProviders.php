<?php

namespace App\Filament\Admin\Resources\AIProviders\Pages;

use App\Filament\Admin\Resources\AIProviders\AIProviderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAIProviders extends ManageRecords
{
    protected static string $resource = AIProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
