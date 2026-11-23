<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\Models\Payment;

/** Anything clients pay for online (booking deposit, shop order, gift card, package, AI credits...). */
interface Payable
{
    public function paymentDescription(): string;

    /** Called once (inside a transaction) when a payment for this payable succeeds. */
    public function onPaymentSucceeded(Payment $payment): void;

    /** Called when a payment fails/expires (e.g. release slot hold / stock reservation). */
    public function onPaymentFailed(Payment $payment): void;
}
