<?php

namespace App\Domain\Payments\Events;

use App\Domain\Payments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentSucceeded
{
    use Dispatchable;

    public function __construct(public Payment $payment) {}
}
