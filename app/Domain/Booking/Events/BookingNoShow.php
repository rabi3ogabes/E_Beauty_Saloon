<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;

final class BookingNoShow extends BookingEvent
{
    public function __construct(Booking $booking, public int $fee = 0, public int $refunded = 0)
    {
        parent::__construct($booking);
    }
}
