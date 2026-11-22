<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\BookingTransitions;
use Illuminate\Support\Facades\DB;

/** Confirms a booking (salon action, or automatically once its online payment succeeds). */
class ConfirmBooking
{
    public function __construct(protected BookingTransitions $transitions) {}

    public function handle(Booking $booking, ?string $note = null): Booking
    {
        abort_if(tenant()?->isReadOnly(), 423);

        return DB::transaction(function () use ($booking, $note) {
            $booking = Booking::withoutGlobalScopes()->lockForUpdate()->findOrFail($booking->id);
            if ($booking->status === BookingStatus::Confirmed) {
                return $booking; // idempotent
            }
            $this->transitions->to($booking, BookingStatus::Confirmed, $note);
            $this->transitions->log($booking, 'confirmed');
            BookingConfirmed::dispatch($booking);

            return $booking;
        });
    }
}
