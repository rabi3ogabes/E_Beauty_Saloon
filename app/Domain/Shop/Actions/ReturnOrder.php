<?php

namespace App\Domain\Shop\Actions;

use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * delivered → returned. Puts the items back into the fulfilment branch (movement type "return")
 * and refunds the client — to the original payment methods, or as wallet store credit.
 */
class ReturnOrder
{
    public function __construct(protected OrderTransition $transition, protected StockLedger $stock, protected CancelOrder $cancel) {}

    public function handle(Order $order, bool $asStoreCredit = false, bool $restock = true, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $asStoreCredit, $restock, $note) {
            $order = $this->transition->lock($order);
            $this->transition->assertCan($order, OrderStatus::Returned);

            foreach ($order->items as $item) {
                if ($restock && $item->product_variant_id && $order->stock_state === 'deducted') {
                    $this->stock->move($order->branch_id, $item->product_variant_id, $item->qty, 'return', $order, "order:{$order->id}:item:{$item->id}:return", $order->number.' returned');
                }
                $item->update(['returned_qty' => $item->qty]);
            }

            $refunded = $this->cancel->refundAll($order, $asStoreCredit, 'order_returned');

            return $this->transition->apply($order, OrderStatus::Returned, $note, [
                'stock_state' => $restock ? 'returned' : $order->stock_state,
                'refunded' => $order->refunded + $refunded,
            ]);
        });
    }
}
