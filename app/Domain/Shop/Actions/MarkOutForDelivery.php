<?php

namespace App\Domain\Shop\Actions;

use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Driver;
use App\Domain\Shop\Models\Order;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/** Delivery / same-day orders: confirmed|preparing → out_for_delivery (optionally assigning a driver). */
class MarkOutForDelivery
{
    public function __construct(protected OrderTransition $transition, protected AssignDriver $assign) {}

    public function handle(Order $order, ?Driver $driver = null, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $driver, $note) {
            $order = $this->transition->lock($order);
            if (! $order->isDelivery()) {
                throw new DomainException(__('shop.errors.not_delivery'));
            }
            $this->transition->assertCan($order, OrderStatus::OutForDelivery);
            if ($driver) {
                $order = $this->assign->handle($order, $driver);
            }

            return $this->transition->apply($order, OrderStatus::OutForDelivery, $note);
        });
    }
}
