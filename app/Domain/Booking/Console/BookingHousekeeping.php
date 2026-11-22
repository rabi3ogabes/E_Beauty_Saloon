<?php

namespace App\Domain\Booking\Console;

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\OfferWaitlistSlot;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SlotHold;
use App\Domain\Booking\Models\WaitlistEntry;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Every 5 minutes: cancel unpaid online bookings whose payment window passed, purge expired holds,
 * expire waitlist offers (and pass the slot to the next person in line), close past waitlist entries.
 */
class BookingHousekeeping extends Command
{
    protected $signature = 'booking:housekeeping';

    protected $description = 'Expire unpaid bookings, slot holds and waitlist offers';

    public function handle(TenantContext $context, CancelBooking $cancel, OfferWaitlistSlot $offer): int
    {
        SlotHold::withoutGlobalScopes()->where('expires_at', '<', now()->subHour())->whereNull('booking_id')->delete();

        Tenant::query()->get()->filter(fn (Tenant $t) => $t->hasModule('booking'))->each(function (Tenant $tenant) use ($context, $cancel, $offer) {
            $context->run($tenant, function () use ($cancel, $offer) {
                $grace = max(BookingSettings::get('hold_minutes'), 10) + 20; // hosted page + webhook delay
                Booking::query()->where('status', BookingStatus::PendingPayment->value)->where('amount_paid', 0)
                    ->where('created_at', '<', now()->subMinutes($grace))->get()
                    ->each(fn (Booking $b) => rescue(fn () => $cancel->handle($b, 'system', __('booking.history.payment_expired'))));

                WaitlistEntry::query()->where('status', 'offered')->where('offer_expires_at', '<', now())->with('branch')->get()
                    ->each(function (WaitlistEntry $entry) use ($offer) {
                        $entry->forceFill(['status' => 'expired'])->save();
                        SlotHold::where('token', 'like', 'wl'.$entry->id.'.%')->whereNull('booking_id')->delete();
                        if ($entry->offered_starts_at?->isFuture()) {
                            rescue(fn () => $offer->handle($entry->branch, $entry->offered_starts_at->setTimezone($entry->branch->timezone)->toDateString(), $entry->offered_starts_at->toImmutable()));
                        }
                    });

                WaitlistEntry::query()->where('status', 'waiting')->whereDate('date_to', '<', now()->toDateString())->update(['status' => 'expired']);
            });
        });

        return self::SUCCESS;
    }
}
