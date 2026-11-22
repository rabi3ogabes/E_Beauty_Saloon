<?php

namespace App\Filament\Dashboard\Resources\PurchaseOrders\Pages;

use App\Filament\Dashboard\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('workspace.purchase_orders.add'))->icon(Heroicon::OutlinedPlus)];
    }
}
