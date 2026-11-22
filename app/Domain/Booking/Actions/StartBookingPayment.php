<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\BookingPayments;
use App\Domain\Booking\Support\PayableBooking;
use App\Domain\Payments\Actions\CreateOnlinePayment;
use App\Domain\Payments\Models\Payment;
use App\Support\Exceptions\DomainException;

/**
 * Starts the hosted payment for a booking's deposit or full amount on the salon's gateway.
 * meta.return_to sends the client back to our confirmation page after /payments/return/{key}.
 */
class StartBookingPayment
{
    public function __construct(protected CreateOnlinePayment $create, protected BookingPayments $payments) {}

    public function handle(Booking $booking, string $mode, string $returnTo): Payment
    {
        if (! in_array($booking->status, [BookingStatus::PendingPayment, BookingStatus::Confirmed], true)) {
            throw new DomainException(__('booking.errors.cannot_pay'));
        }
        $amount = $this->payments->dueNow($booking, $mode);
        if ($amount <= 0) {
            throw new DomainException(__('booking.errors.nothing_to_pay'));
        }
        $gateway = $this->payments->gateway($mode, $booking->branch_id) ?? throw new DomainException(__('booking.errors.no_gateway'));
        $booking->loadMissing('client');

        $payment = $this->create->handle(
            PayableBooking::fromBooking($booking),
            $gateway,
            $amount,
            returnUrl: route('booking.payment.return', ['reference' => $booking->reference]),
            customer: array_filter(['name' => $booking->client?->name, 'phone' => $booking->client?->phone, 'email' => $booking->client?->email]),
            purpose: $mode === 'deposit' ? 'deposit' : 'payment',
            branchId: $booking->branch_id,
            clientId: $booking->client_id,
        );
        $payment->update(['meta' => array_merge($payment->meta ?? [], ['return_to' => $returnTo, 'booking_reference' => $booking->reference])]);

        return $payment;
    }
}
