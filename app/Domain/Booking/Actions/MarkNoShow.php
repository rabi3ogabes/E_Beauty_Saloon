<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Events\BookingNoShow;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\BookingPayments;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Booking\Support\BookingTransitions;
use App\Domain\CRM\Models\Client;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The client did not come. Keeps booking.no_show_fee_percent of the money collected online as the
 * no-show fee and refunds the rest; increments the client's no-show counter.
 */
class MarkNoShow
{
    public function __construct(protected BookingTransitions $transitions, protected BookingPayments $payments) {}

    public function handle(Booking $booking, ?string $note = null): Booking
    {
        abort_if(tenant()?->isReadOnly(), 423);

        [$booking, $fee] = DB::transaction(function () use ($booking, $note) {
            $booking = Booking::withoutGlobalScopes()->lockForUpdate()->findOrFail($booking->id);
            if ($booking->starts_at->isFuture()) {
                throw new DomainException(__('booking.errors.no_show_too_early'));
            }
            $this->payments->recalculatePaid($booking);
            $fee = $this->payments->feeFromPaid($booking, BookingSettings::get('no_show_fee_percent'));
            $this->transitions->to($booking, BookingStatus::NoShow, $note, ['fee_charged' => $fee]);
            if ($booking->client_id) {
                Client::withoutGlobalScopes()->whereKey($booking->client_id)->increment('no_shows_count');
            }

            return [$booking, $fee];
        });

        $refunded = $this->payments->refund($booking, $booking->amount_paid - $fee, 'no_show_partial_refund');
        $this->transitions->log($booking, 'no_show', ['fee' => $fee, 'refunded' => $refunded]);
        BookingNoShow::dispatch($booking->fresh(), $fee, $refunded);

        return $booking->fresh();
    }
}
