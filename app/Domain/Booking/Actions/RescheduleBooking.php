<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Availability\AvailabilityEngine;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Events\BookingRescheduled;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\BookingLines;
use App\Domain\Booking\Support\BookingLock;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Booking\Support\BookingTransitions;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Moves a booking (and optionally re-assigns staff / resizes items), re-validated by the engine.
 * Clients are limited by booking.reschedule_limit and cannot reschedule inside the cancellation window.
 *
 * $options: by (client|salon), keep_staff (bool, default true), staff ([itemIndex => staffId|null]),
 *           durations ([itemIndex => minutes]), note
 */
class RescheduleBooking
{
    public function __construct(protected AvailabilityEngine $engine, protected BookingLock $lock, protected BookingTransitions $transitions) {}

    public function handle(Booking $booking, CarbonImmutable|string $start, array $options = []): Booking
    {
        abort_if(tenant()?->isReadOnly(), 423);
        $by = $options['by'] ?? 'salon';
        $start = CarbonImmutable::parse($start)->utc();

        if (! in_array($booking->status, [BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::CheckedIn], true)
            || ($by === 'client' && $booking->status === BookingStatus::CheckedIn)) {
            throw new DomainException(__('booking.errors.cannot_reschedule'));
        }
        if ($by === 'client') {
            if ($booking->reschedule_count >= BookingSettings::get('reschedule_limit')) {
                throw new DomainException(__('booking.errors.reschedule_limit'));
            }
            if (now()->diffInMinutes($booking->starts_at, false) < BookingSettings::get('cancellation_window_hours') * 60) {
                throw new DomainException(__('booking.errors.reschedule_window', ['hours' => BookingSettings::get('cancellation_window_hours')]));
            }
        }

        $previous = $booking->starts_at->copy();
        $online = $by === 'client';

        $booking = $this->lock->run((int) $booking->tenant_id, fn () => DB::transaction(function () use ($booking, $start, $options, $by, $online, $previous) {
            $booking = Booking::withoutGlobalScopes()->with(['items.service.branches', 'branch'])->lockForUpdate()->findOrFail($booking->id);
            $lines = BookingLines::fromBooking($booking, $options['keep_staff'] ?? true, $options['staff'] ?? [], $options['durations'] ?? []);
            $slot = $this->engine->quote($booking->branch, $lines, $start, [
                'online' => false, 'enforce_notice' => $online, 'enforce_max_days' => $online, 'ignore_booking_id' => $booking->id,
            ]);
            if (! $slot) {
                throw new DomainException(__('booking.errors.slot_taken'));
            }

            foreach ($booking->items->values() as $i => $item) {
                $s = $slot->items[$i];
                $item->update([
                    'staff_id' => $s['staff_id'], 'resource_id' => $s['resource_id'],
                    'starts_at' => CarbonImmutable::createFromTimestampUTC($s['starts_at']), 'ends_at' => CarbonImmutable::createFromTimestampUTC($s['ends_at']),
                    'duration_minutes' => $s['duration_minutes'],
                ]);
            }
            $booking->forceFill([
                'starts_at' => $slot->startsAt(), 'ends_at' => $slot->endsAt(),
                'reschedule_count' => $booking->reschedule_count + ($by === 'client' ? 1 : 0),
                'reminder_24h_sent_at' => null, 'reminder_2h_sent_at' => null,
            ])->save();

            $tz = $booking->branch->timezone;
            $this->transitions->record($booking, $booking->status, $booking->status, $options['note'] ?? __('booking.history.rescheduled', [
                'from' => $previous->copy()->setTimezone($tz)->format('Y-m-d H:i'), 'to' => $slot->startsAt()->setTimezone($tz)->format('Y-m-d H:i'),
            ]));
            $this->transitions->log($booking, 'rescheduled', ['by' => $by, 'from' => $previous->toIso8601String(), 'to' => $slot->startsAt()->toIso8601String()]);

            return $booking;
        }));

        if (! $previous->equalTo($booking->starts_at) || ($options['notify'] ?? true)) {
            BookingRescheduled::dispatch($booking, $previous, $by);
        }

        return $booking->fresh(['items']);
    }
}
