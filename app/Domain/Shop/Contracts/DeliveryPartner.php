<?php

namespace App\Domain\Shop\Contracts;

use App\Domain\Shop\Models\Driver;
use App\Domain\Shop\Models\Order;

/**
 * Local courier integration (e.g. a Doha same-day courier). Register drivers with
 * app(DeliveryPartners::class)->extend('courier_key', fn () => new MyCourier(...)).
 */
interface DeliveryPartner
{
    public function key(): string;

    /** Hand the order over to the courier. Returns the courier's tracking reference, if any. */
    public function dispatch(Order $order, Driver $driver): ?string;

    public function cancel(Order $order): void;

    /** Status from the courier: pending|picked_up|delivered|failed (null = unknown / manual). */
    public function status(Order $order): ?string;

    public function trackingUrl(Order $order): ?string;
}
