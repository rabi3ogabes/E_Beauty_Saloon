<?php

namespace App\Filament\Dashboard\Resources\PosShifts\Pages;

use App\Filament\Dashboard\Resources\PosShifts\ShiftResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewShift extends ViewRecord
{
    protected static string $resource = ShiftResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['register', 'user', 'cashMovements']);
    }

    protected function getHeaderActions(): array
    {
        return [ShiftResource::zReportAction()->visible(fn () => $this->record->status === 'closed')];
    }
}
