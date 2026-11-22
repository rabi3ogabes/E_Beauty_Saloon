<?php

namespace App\Filament\Dashboard\Resources\PosSales\Pages;

use App\Filament\Dashboard\Resources\PosSales\SaleResource;
use Filament\Resources\Pages\ListRecords;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;
}
