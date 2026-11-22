<?php

namespace App\Domain\Shop\Actions;

use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Order;
use Illuminate\Support\Facades\DB;

/** confirmed (or out_for_delivery after a failed attempt) → preparing. */
class MarkPreparing
{
    public function __construct(protected OrderTransition $transition) {}

    public function handle(Order $order, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $note) {
            $order = $this->transition->lock($order);
            $this->transition->assertCan($order, OrderStatus::Preparing);

            return $this->transition->apply($order, OrderStatus::Preparing, $note);
        });
    }
}
