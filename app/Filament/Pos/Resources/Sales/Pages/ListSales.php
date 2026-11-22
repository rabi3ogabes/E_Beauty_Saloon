<?php

namespace App\Filament\Pos\Resources\Sales\Pages;

use App\Filament\Pos\Resources\Sales\SaleResource;
use Filament\Resources\Pages\ListRecords;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;
}
