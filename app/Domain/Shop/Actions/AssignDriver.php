<?php

namespace App\Domain\Shop\Actions;

use App\Domain\Shop\Delivery\DeliveryPartners;
use App\Domain\Shop\Models\Driver;
use App\Domain\Shop\Models\Order;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Assigns an internal driver (or a delivery-partner driver) to a delivery order. Partner drivers
 * hand the order to the courier integration (DeliveryPartner::dispatch).
 */
class AssignDriver
{
    public function __construct(protected DeliveryPartners $partners) {}

    public function handle(Order $order, ?Driver $driver): Order
    {
        return DB::transaction(function () use ($order, $driver) {
            $order = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
            if (! $order->isDelivery() || $order->status->isFinal()) {
                throw new DomainException(__('shop.errors.driver_not_allowed'));
            }
            $driver = $driver ? Driver::withoutGlobalScopes()->findOrFail($driver->id) : null;
            if ($driver && (! $driver->is_active || $driver->tenant_id !== $order->tenant_id)) {
                throw new DomainException(__('shop.errors.driver_inactive'));
            }

            $partner = $driver?->partner ?? 'internal';
            $reference = $partner !== 'internal' && $driver ? $this->partners->driver($partner)->dispatch($order, $driver) : null;

            $order->forceFill(['driver_id' => $driver?->id, 'delivery_partner' => $partner === 'internal' ? null : $partner, 'partner_reference' => $reference])->save();
            activity()->performedOn($order)->withProperties(['driver_id' => $driver?->id, 'partner' => $partner])->log('order.driver_assigned');

            return Order::withoutGlobalScopes()->findOrFail($order->id);
        });
    }
}
