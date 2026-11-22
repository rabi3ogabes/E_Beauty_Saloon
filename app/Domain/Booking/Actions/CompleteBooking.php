<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Events\BookingCompleted;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\BookingTransitions;
use Illuminate\Support\Facades\DB;

/** Service finished (also called by POS when the sale settles). */
class CompleteBooking
{
    public function __construct(protected BookingTransitions $transitions) {}

    public function handle(Booking $booking, ?string $note = null): Booking
    {
        abort_if(tenant()?->isReadOnly(), 423);

        return DB::transaction(function () use ($booking, $note) {
            $booking = Booking::withoutGlobalScopes()->lockForUpdate()->findOrFail($booking->id);
            if ($booking->status === BookingStatus::Completed) {
                return $booking; // idempotent
            }
            $this->transitions->to($booking, BookingStatus::Completed, $note);
            $this->transitions->log($booking, 'completed');
            BookingCompleted::dispatch($booking);

            return $booking;
        });
    }
}
