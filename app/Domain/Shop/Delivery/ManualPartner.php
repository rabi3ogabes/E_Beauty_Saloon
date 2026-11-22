<?php

namespace App\Domain\Shop\Delivery;

use App\Domain\Shop\Contracts\DeliveryPartner;
use App\Domain\Shop\Models\Driver;
use App\Domain\Shop\Models\Order;

/**
 * Stub partner for couriers without an API: staff phone/WhatsApp the courier and update the
 * order manually. Generates a local reference so the order shows who has it.
 */
class ManualPartner implements DeliveryPartner
{
    public function key(): string
    {
        return 'manual';
    }

    public function dispatch(Order $order, Driver $driver): ?string
    {
        return 'MAN-'.$order->number;
    }

    public function cancel(Order $order): void {}

    public function status(Order $order): ?string
    {
        return null;
    }

    public function trackingUrl(Order $order): ?string
    {
        return null;
    }
}
