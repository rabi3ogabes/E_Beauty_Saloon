<?php

namespace App\Domain\Shop\Actions;

use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * pending → confirmed. Turns the reservation into a real stock deduction (movement type "order")
 * from the order's fulfilment branch — the same branch_stock row POS sells from.
 */
class ConfirmOrder
{
    public function __construct(protected OrderTransition $transition, protected StockLedger $stock) {}

    public function handle(Order $order, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $note) {
            $order = $this->transition->lock($order);
            $this->transition->assertCan($order, OrderStatus::Confirmed);

            foreach ($order->items()->with('variant.product')->get() as $item) {
                if (! $item->product_variant_id || ! ($item->variant?->product?->track_stock ?? true)) {
                    continue;
                }
                if ($order->stock_state === 'reserved') {
                    $this->stock->release($order->branch_id, $item->product_variant_id, $item->qty);
                }
                $this->stock->move($order->branch_id, $item->product_variant_id, -$item->qty, 'order', $order, "order:{$order->id}:item:{$item->id}", $order->number);
            }

            return $this->transition->apply($order, OrderStatus::Confirmed, $note, ['stock_state' => 'deducted']);
        });
    }
}
