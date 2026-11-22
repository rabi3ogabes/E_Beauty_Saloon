<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Events\BookingCheckedIn;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\BookingTransitions;
use Illuminate\Support\Facades\DB;

/** Client arrived. POS picks up checked-in bookings as open tickets. */
class CheckInBooking
{
    public function __construct(protected BookingTransitions $transitions) {}

    public function handle(Booking $booking, ?string $note = null): Booking
    {
        abort_if(tenant()?->isReadOnly(), 423);

        return DB::transaction(function () use ($booking, $note) {
            $booking = Booking::withoutGlobalScopes()->lockForUpdate()->findOrFail($booking->id);
            if ($booking->status === BookingStatus::CheckedIn) {
                return $booking; // idempotent
            }
            $this->transitions->to($booking, BookingStatus::CheckedIn, $note);
            $this->transitions->log($booking, 'checked_in');
            BookingCheckedIn::dispatch($booking);

            return $booking;
        });
    }
}
