<?php

namespace App\Filament\Dashboard\Resources\Shop\Orders\Pages;

use App\Filament\Dashboard\Pages\Shop\OrdersBoard;
use App\Filament\Dashboard\Resources\Shop\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('board')->label(__('shop.admin.board.title'))->icon(Heroicon::OutlinedViewColumns)->color('gray')->url(OrdersBoard::getUrl()),
        ];
    }
}
