<?php

namespace App\Domain\Booking\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Realtime nudge for open calendars on the private channel "tenant.{id}.bookings".
 * Broadcasting-ready (Reverb/Pusher); the calendar also polls every 20s as a fallback.
 */
class BookingCalendarUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $tenantId, public int $bookingId, public int $branchId, public string $status, public string $startsAt, public string $type) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->tenantId.'.bookings')];
    }

    public function broadcastAs(): string
    {
        return 'booking.updated';
    }
}
