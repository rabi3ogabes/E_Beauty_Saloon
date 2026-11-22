<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Availability\AvailabilityEngine;
use App\Domain\Booking\Availability\LineBuilder;
use App\Domain\Booking\Models\WaitlistEntry;
use App\Domain\Booking\Notifications\BookingNotifier;
use App\Domain\Booking\Support\BookingLinks;
use App\Domain\Branches\Models\Branch;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;

/**
 * A slot freed up (cancellation / reschedule / expired offer): offer it to the FIRST matching waitlist
 * entry for that branch and date. The slot is held for 30 minutes and the client gets a WhatsApp
 * message with a signed claim link valid for the same 30 minutes.
 */
class OfferWaitlistSlot
{
    public const CLAIM_MINUTES = 30;

    public function __construct(
        protected AvailabilityEngine $engine,
        protected LineBuilder $lineBuilder,
        protected HoldSlot $hold,
        protected BookingNotifier $notifier,
    ) {}

    public function handle(Branch $branch, string $localDate, ?CarbonImmutable $preferredStart = null): ?WaitlistEntry
    {
        $entries = WaitlistEntry::query()->withoutGlobalScopes()->where('tenant_id', $branch->tenant_id)
            ->where('branch_id', $branch->id)->where('status', 'waiting')
            ->whereDate('date_from', '<=', $localDate)->whereDate('date_to', '>=', $localDate)
            ->orderBy('created_at')->orderBy('id')->get();

        foreach ($entries as $entry) {
            try {
                $lines = $this->lineBuilder->build($branch, array_map(fn ($id) => ['service_id' => $id, 'staff_id' => $entry->staff_id], $entry->service_ids ?? []));
            } catch (DomainException) {
                continue;
            }
            $day = $this->engine->day($branch, $lines, $localDate, ['cache' => false]);
            $slots = array_values(array_filter($day->slots, fn ($s) => $entry->time_window === 'any' || $s->period($branch->timezone) === $entry->time_window));
            if (! $slots) {
                continue;
            }
            $slot = $slots[0];
            if ($preferredStart) {
                foreach ($slots as $candidate) {
                    if ($candidate->start === $preferredStart->getTimestamp()) {
                        $slot = $candidate;
                        break;
                    }
                }
            }

            try {
                $this->hold->handle($branch, $lines, $slot->startsAt(), replaceToken: 'wl'.$entry->id, minutes: self::CLAIM_MINUTES, token: 'wl'.$entry->id);
            } catch (DomainException) {
                continue;
            }
            $entry->forceFill([
                'status' => 'offered', 'offered_at' => now(), 'offered_starts_at' => $slot->startsAt(), 'offer_expires_at' => now()->addMinutes(self::CLAIM_MINUTES),
            ])->save();
            $this->notifier->waitlistOffer($entry, $slot->startsAt(), BookingLinks::waitlistClaim($entry, self::CLAIM_MINUTES));
            activity()->performedOn($entry)->withProperties(['starts_at' => $slot->startsAt()->toIso8601String()])->log('booking.waitlist_offered');

            return $entry;
        }

        return null;
    }
}
