<?php

namespace App\Filament\Dashboard\Resources\Shop\Orders\Pages;

use App\Filament\Dashboard\Resources\Shop\Orders\OrderResource;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        $actions = collect(OrderResource::moveActions())->map(fn ($a) => $a->after(fn () => $this->record->refresh()));

        return [
            ...$actions->take(5)->all(),
            OrderResource::documentAction('packing_slip', 'packing-slip', Heroicon::OutlinedClipboardDocumentList)->button()->color('gray'),
            OrderResource::documentAction('invoice', 'invoice', Heroicon::OutlinedDocumentText)->button()->color('gray'),
            ActionGroup::make($actions->slice(5)->values()->all()),
        ];
    }
}
