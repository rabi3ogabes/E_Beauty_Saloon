<?php

namespace App\Domain\Booking\Support;

use App\Domain\Booking\Actions\ApplyBookingPayment;
use App\Domain\Booking\Actions\HandleBookingPaymentFailure;
use App\Domain\Booking\Models\Booking;
use App\Domain\Payments\Contracts\Payable;
use App\Domain\Payments\Models\Payment;

/**
 * Payable view of a Booking, used to start online payments without changing the shared core model.
 * It keeps the Booking morph class, so payments are attached to the real booking (`$booking->payments`);
 * settlement is applied by the ApplyBookingPayment / HandleBookingPaymentFailure listeners
 * (PaymentSucceeded / PaymentFailed), which call the same methods below.
 */
class PayableBooking extends Booking implements Payable
{
    protected $table = 'bookings';

    public function getMorphClass()
    {
        return (new Booking)->getMorphClass();
    }

    public function getForeignKey()
    {
        return 'booking_id';
    }

    public static function fromBooking(Booking $booking): self
    {
        $payable = (new self)->newFromBuilder($booking->getAttributes());
        $payable->exists = true;

        return $payable;
    }

    public function paymentDescription(): string
    {
        return __('booking.payment.description', ['reference' => $this->reference, 'salon' => tenant()?->name]);
    }

    public function onPaymentSucceeded(Payment $payment): void
    {
        app(ApplyBookingPayment::class)->handle($payment);
    }

    public function onPaymentFailed(Payment $payment): void
    {
        app(HandleBookingPaymentFailure::class)->handle($payment);
    }
}
