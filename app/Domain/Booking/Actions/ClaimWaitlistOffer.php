<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WaitlistEntry;
use App\Support\Exceptions\DomainException;

/** The client tapped the claim link in time: book the offered slot (it was held for them). */
class ClaimWaitlistOffer
{
    public function __construct(protected CreateBooking $create) {}

    public function handle(WaitlistEntry $entry): Booking
    {
        if ($entry->status !== 'offered' || ! $entry->offer_expires_at || $entry->offer_expires_at->isPast()) {
            throw new DomainException(__('booking.errors.offer_expired'));
        }
        $entry->loadMissing(['client', 'branch']);

        $booking = $this->create->handle([
            'branch' => $entry->branch,
            'items' => array_map(fn ($id) => ['service_id' => $id, 'staff_id' => $entry->staff_id], $entry->service_ids ?? []),
            'starts_at' => $entry->offered_starts_at,
            'client' => $entry->client,
            'source' => 'website',
            'hold_token' => 'wl'.$entry->id,
            'enforce_notice' => false,
            'online' => true,
            'history_note' => __('booking.history.from_waitlist'),
        ]);
        $entry->forceFill(['status' => 'booked'])->save();

        return $booking;
    }
}
