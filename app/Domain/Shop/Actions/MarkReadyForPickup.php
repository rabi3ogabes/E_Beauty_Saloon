<?php

namespace App\Domain\Shop\Actions;

use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Order;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/** Pickup orders only: confirmed|preparing → ready_for_pickup. */
class MarkReadyForPickup
{
    public function __construct(protected OrderTransition $transition) {}

    public function handle(Order $order, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $note) {
            $order = $this->transition->lock($order);
            if (! $order->isPickup()) {
                throw new DomainException(__('shop.errors.not_pickup'));
            }
            $this->transition->assertCan($order, OrderStatus::ReadyForPickup);

            return $this->transition->apply($order, OrderStatus::ReadyForPickup, $note, ['ready_estimate_at' => now()]);
        });
    }
}
