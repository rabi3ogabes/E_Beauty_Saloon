<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SlotHold;
use App\Domain\Booking\Support\BookingPayments;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Booking\Support\BookingTransitions;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a booking.
 *  - by "client": allowed until the start time; inside the cancellation window the late-cancel fee
 *    (booking.late_cancel_fee_percent of the money collected online) is kept, the rest is refunded.
 *  - by "salon" / "system": no fee, everything collected is refunded (unless $keepFee is given).
 */
class CancelBooking
{
    public function __construct(protected BookingTransitions $transitions, protected BookingPayments $payments) {}

    public function handle(Booking $booking, string $by = 'client', ?string $reason = null, ?int $keepFee = null): Booking
    {
        abort_if(tenant()?->isReadOnly(), 423);

        [$booking, $fee] = DB::transaction(function () use ($booking, $by, $reason, $keepFee) {
            $booking = Booking::withoutGlobalScopes()->lockForUpdate()->findOrFail($booking->id);
            $to = $by === 'client' ? BookingStatus::CancelledByClient : BookingStatus::CancelledBySalon;
            if ($by === 'client' && $booking->starts_at->isPast()) {
                throw new DomainException(__('booking.errors.too_late_to_cancel'));
            }

            $this->payments->recalculatePaid($booking);
            $fee = 0;
            if ($by === 'client' && $this->isLate($booking)) {
                $fee = $this->payments->feeFromPaid($booking, BookingSettings::get('late_cancel_fee_percent'));
            } elseif ($keepFee !== null) {
                $fee = min($booking->amount_paid, max(0, $keepFee));
            }

            $this->transitions->to($booking, $to, $reason, ['cancellation_reason' => $reason ? mb_substr($reason, 0, 250) : null, 'fee_charged' => $fee]);
            SlotHold::where('booking_id', $booking->id)->delete();

            return [$booking, $fee];
        });

        $refunded = $this->payments->refund($booking, $booking->amount_paid - $fee, 'booking_cancelled');
        $this->transitions->log($booking, 'cancelled', ['by' => $by, 'reason' => $reason, 'fee' => $fee, 'refunded' => $refunded]);
        BookingCancelled::dispatch($booking->fresh(), $by, $fee, $refunded);

        return $booking->fresh();
    }

    /** Inside the cancellation window? */
    public function isLate(Booking $booking): bool
    {
        return now()->diffInMinutes($booking->starts_at, false) < BookingSettings::get('cancellation_window_hours') * 60;
    }
}
