<?php

namespace App\Filament\Admin\Resources\AITaskPrices\Pages;

use App\Domain\AIStudio\Models\AITaskPrice;
use App\Filament\Admin\Resources\AITaskPrices\AITaskPriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAITaskPrices extends ManageRecords
{
    protected static string $resource = AITaskPriceResource::class;

    public function mount(): void
    {
        parent::mount();
        AITaskPrice::seedDefaults(); // built-in tasks always listed
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
