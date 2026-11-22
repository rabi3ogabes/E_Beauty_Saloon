<?php

namespace App\Filament\Dashboard\Resources\Marketing\Promotions\Pages;

use App\Filament\Dashboard\Resources\Marketing\Promotions\PromotionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePromotions extends ManageRecords
{
    protected static string $resource = PromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()];
    }
}
