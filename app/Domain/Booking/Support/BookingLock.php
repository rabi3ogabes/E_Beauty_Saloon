<?php

namespace App\Domain\Booking\Support;

use App\Support\Exceptions\DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Serialises writes that occupy the calendar (holds, bookings, moves) per tenant, so the
 * "re-check availability then insert" sequence can never interleave between two requests.
 * Staff can work at several branches, hence the tenant (not branch) granularity.
 * Re-entrant inside one process (group / recurring bookings call CreateBooking repeatedly).
 */
class BookingLock
{
    protected static array $held = [];

    public function run(int $tenantId, \Closure $callback): mixed
    {
        $key = 'booking:lock:'.$tenantId;
        if (isset(static::$held[$key])) {
            return $callback();
        }

        try {
            return Cache::lock($key, 15)->block(8, function () use ($key, $callback) {
                static::$held[$key] = true;
                try {
                    return $callback();
                } finally {
                    unset(static::$held[$key]);
                }
            });
        } catch (LockTimeoutException) {
            throw new DomainException(__('booking.errors.busy_try_again'));
        }
    }
}
