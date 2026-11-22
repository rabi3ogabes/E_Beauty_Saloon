<?php

namespace App\Filament\Dashboard\Resources\TimeOff\Pages;

use App\Filament\Dashboard\Resources\TimeOff\TimeOffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageTimeOff extends ManageRecords
{
    protected static string $resource = TimeOffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('workspace.time_off.add'))->icon(Heroicon::OutlinedPlus)
                ->mutateDataUsing(fn (array $data) => $data + ['status' => 'pending']),
        ];
    }
}
