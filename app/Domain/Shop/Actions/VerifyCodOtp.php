<?php

namespace App\Domain\Shop\Actions;

use App\Domain\CRM\Actions\VerifyClientOtp;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Order;
use App\Support\Exceptions\DomainException;

/** The client confirms a COD order with the one-time code sent to the order phone → order confirmed. */
class VerifyCodOtp
{
    public function __construct(protected VerifyClientOtp $verify, protected ConfirmOrder $confirm) {}

    public function handle(Order $order, string $code): Order
    {
        if (! $order->cod_otp_required || $order->cod_otp_verified_at || $order->status !== OrderStatus::Pending) {
            throw new DomainException(__('shop.errors.otp_not_needed'));
        }
        $this->verify->handle($order->customer_phone, $code, $order->customer_name, 'shop');
        $order->forceFill(['cod_otp_verified_at' => now()])->save();

        return $this->confirm->handle($order, 'cod_otp');
    }
}
