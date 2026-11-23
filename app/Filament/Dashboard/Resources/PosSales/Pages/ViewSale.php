<?php

namespace App\Filament\Dashboard\Resources\PosSales\Pages;

use App\Filament\Dashboard\Resources\PosSales\SaleResource;
use App\Filament\Pos\Support\SaleActions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['items.staff', 'items.sale', 'salePayments.sale', 'refunds', 'client', 'branch', 'register', 'cashier']);
    }

    protected function getHeaderActions(): array
    {
        return [SaleActions::receipt(), SaleActions::refund(), SaleActions::void()];
    }
}
