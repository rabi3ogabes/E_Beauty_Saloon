<?php

namespace App\Filament\Dashboard\Resources\PosShifts\Pages;

use App\Filament\Dashboard\Resources\PosShifts\ShiftResource;
use Filament\Resources\Pages\ListRecords;

class ListShifts extends ListRecords
{
    protected static string $resource = ShiftResource::class;
}
