<?php

namespace App\Domain\Payments\Events;

use App\Domain\Payments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentFailed
{
    use Dispatchable;

    public function __construct(public Payment $payment) {}
}
