<?php

namespace App\Filament\Dashboard\Resources\Bookings\Pages;

use App\Filament\Dashboard\Resources\Bookings\BookingResource;
use App\Filament\Dashboard\Support\BookingActions;
use Filament\Resources\Pages\ViewRecord;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return array_map(fn ($action) => $action->after(fn () => $this->record->refresh()), BookingActions::all());
    }

    public function getTitle(): string
    {
        return __('booking.admin.label').' '.$this->record->reference;
    }
}
