<?php

namespace App\Filament\Dashboard\Resources\StockMovements\Pages;

use App\Filament\Dashboard\Resources\StockMovements\StockMovementResource;
use Filament\Resources\Pages\ListRecords;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    public function getSubheading(): ?string
    {
        return __('workspace.inventory.movements_subheading');
    }
}
