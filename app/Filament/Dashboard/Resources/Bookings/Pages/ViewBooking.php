<?php

namespace App\Filament\Dashboard\Resources\Bookings\Pages;

use App\Filament\Dashboard\Resources\Bookings\BookingResource;
use App\Filament\Dashboard\Support\BookingActions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    public const RELATIONS = ['branch', 'client', 'items.service', 'items.staff', 'items.resource', 'items.booking.branch', 'history.user', 'payments'];

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(self::RELATIONS);
    }

    protected function getHeaderActions(): array
    {
        return array_map(fn ($action) => $action->after(fn () => $this->record = $this->record->fresh(self::RELATIONS)), BookingActions::all());
    }

    public function getTitle(): string
    {
        return __('booking.admin.label').' '.$this->record->reference;
    }
}
