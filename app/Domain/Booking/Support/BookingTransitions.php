<?php

namespace App\Domain\Booking\Support;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\BookingStatusHistory;
use App\Support\Exceptions\DomainException;

/** Status changes: validates the transition, stamps timestamps, writes history + activity log. */
class BookingTransitions
{
    public function to(Booking $booking, BookingStatus $to, ?string $note = null, array $attributes = []): Booking
    {
        $from = $booking->status;
        if (! $from->canTransitionTo($to)) {
            throw new DomainException(__('booking.errors.invalid_transition', ['from' => $from->getLabel(), 'to' => $to->getLabel()]));
        }

        $stamp = match ($to) {
            BookingStatus::Confirmed => ['confirmed_at' => now()],
            BookingStatus::CheckedIn => ['checked_in_at' => now()],
            BookingStatus::Completed => ['completed_at' => now()],
            BookingStatus::CancelledByClient, BookingStatus::CancelledBySalon => ['cancelled_at' => now()],
            default => [],
        };
        $booking->forceFill(array_merge($stamp, $attributes, ['status' => $to]))->save();

        $this->record($booking, $from, $to, $note);

        return $booking;
    }

    public function record(Booking $booking, ?BookingStatus $from, BookingStatus $to, ?string $note = null): void
    {
        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => auth('web')->id(),
            'note' => $note ? mb_substr($note, 0, 250) : null,
        ]);
    }

    public function log(Booking $booking, string $event, array $properties = []): void
    {
        activity()->performedOn($booking)->causedBy(auth('web')->user())
            ->withProperties(array_merge(['reference' => $booking->reference, 'status' => $booking->status?->value, 'tenant_id' => $booking->tenant_id, 'branch_id' => $booking->branch_id], $properties))
            ->log('booking.'.$event);
    }
}
