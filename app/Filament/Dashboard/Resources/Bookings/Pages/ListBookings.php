<?php

namespace App\Filament\Dashboard\Resources\Bookings\Pages;

use App\Filament\Dashboard\Pages\StaffCalendar;
use App\Filament\Dashboard\Resources\Bookings\BookingResource;
use App\Filament\Dashboard\Support\BookingAccess;
use App\Filament\Dashboard\Support\BookingActions;
use App\Filament\Dashboard\Support\BookingForm;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('booking.calendar_page.title'))->icon(Heroicon::OutlinedViewColumns)->color('gray')->url(fn () => StaffCalendar::getUrl()),
            Action::make('newBooking')->label(__('booking.admin.new'))->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => BookingAccess::canManage())
                ->schema(BookingForm::schema())
                ->action(function (array $data) {
                    BookingActions::run(fn () => BookingForm::create($data), __('booking.admin.created'));
                }),
        ];
    }
}
