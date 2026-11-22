<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;
use Carbon\CarbonInterface;

final class BookingRescheduled extends BookingEvent
{
    public function __construct(Booking $booking, public CarbonInterface $previousStartsAt, public string $by = 'salon')
    {
        parent::__construct($booking);
    }
}
