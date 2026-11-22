<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\BookingPayments;
use App\Domain\Booking\Support\BookingTransitions;
use App\Domain\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * A booking payment succeeded: recompute amount_paid (idempotent) and confirm a pending booking.
 * If the booking was cancelled meanwhile (payment expired), the money is refunded automatically.
 */
class ApplyBookingPayment
{
    public function __construct(protected BookingPayments $payments, protected BookingTransitions $transitions) {}

    public function handle(Payment $payment): void
    {
        if ($payment->payable_type !== (new Booking)->getMorphClass() || $payment->amount <= 0) {
            return;
        }

        $refundLate = false;
        $booking = DB::transaction(function () use ($payment, &$refundLate) {
            $booking = Booking::withoutGlobalScopes()->lockForUpdate()->find($payment->payable_id);
            if (! $booking) {
                return null;
            }
            $this->payments->recalculatePaid($booking);

            if ($booking->status === BookingStatus::PendingPayment) {
                $this->transitions->to($booking, BookingStatus::Confirmed, __('booking.history.paid_online', ['amount' => money($payment->amount, $payment->currency)]));
                $this->transitions->log($booking, 'confirmed', ['payment_id' => $payment->id]);
                BookingConfirmed::dispatch($booking);
            } elseif ($booking->status->isCancelled()) {
                $refundLate = true;
            } else {
                $this->transitions->log($booking, 'paid', ['payment_id' => $payment->id, 'amount' => $payment->amount]);
            }

            return $booking;
        });

        if ($booking && $refundLate) {
            $this->payments->refund($booking, min($payment->amount, $booking->amount_paid), 'paid_after_cancellation');
        }
    }
}
