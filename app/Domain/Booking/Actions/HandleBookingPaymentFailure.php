<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SlotHold;
use App\Domain\Payments\Models\Payment;

/**
 * A booking payment failed or expired: release the slot. A booking still awaiting payment (and with no
 * other successful payment) is cancelled by the system so the time frees up for other clients.
 */
class HandleBookingPaymentFailure
{
    public function __construct(protected CancelBooking $cancel) {}

    public function handle(Payment $payment): void
    {
        if ($payment->payable_type !== (new Booking)->getMorphClass()) {
            return;
        }
        $booking = Booking::withoutGlobalScopes()->find($payment->payable_id);
        if (! $booking) {
            return;
        }
        SlotHold::where('booking_id', $booking->id)->delete();

        if ($booking->status === BookingStatus::PendingPayment && (int) $booking->amount_paid === 0) {
            $this->cancel->handle($booking, 'system', __('booking.history.payment_failed'));
        }
    }
}
