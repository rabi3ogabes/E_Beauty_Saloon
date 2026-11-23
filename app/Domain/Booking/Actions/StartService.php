<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Events\BookingServiceStarted;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\BookingTransitions;
use Illuminate\Support\Facades\DB;

/** The stylist started the service. */
class StartService
{
    public function __construct(protected BookingTransitions $transitions) {}

    public function handle(Booking $booking, ?string $note = null): Booking
    {
        abort_if(tenant()?->isReadOnly(), 423);

        return DB::transaction(function () use ($booking, $note) {
            $booking = Booking::withoutGlobalScopes()->lockForUpdate()->findOrFail($booking->id);
            if ($booking->status === BookingStatus::InService) {
                return $booking; // idempotent
            }
            $this->transitions->to($booking, BookingStatus::InService, $note);
            $this->transitions->log($booking, 'in_service');
            BookingServiceStarted::dispatch($booking);

            return $booking;
        });
    }
}
