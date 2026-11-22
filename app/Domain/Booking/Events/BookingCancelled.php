<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;

final class BookingCancelled extends BookingEvent
{
    /** @param string $by client|salon|system */
    public function __construct(Booking $booking, public string $by = 'client', public int $fee = 0, public int $refunded = 0)
    {
        parent::__construct($booking);
    }
}
