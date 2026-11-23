<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

class ReceivePurchaseOrder
{
    public function __construct(protected StockLedger $ledger) {}

    /** @param array<int,int>|null $received purchase_order_item_id => qty (null = receive everything outstanding) */
    public function handle(PurchaseOrder $po, ?array $received = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $received) {
            foreach ($po->items as $item) {
                $outstanding = $item->qty - $item->received_qty;
                $qty = min($outstanding, $received[$item->id] ?? $outstanding);
                if ($qty <= 0) {
                    continue;
                }
                $this->ledger->move($po->branch_id, $item->product_variant_id, $qty, 'purchase', $po,
                    "po:{$po->id}:item:{$item->id}:".($item->received_qty + $qty), unitCost: $item->unit_cost);
                $item->increment('received_qty', $qty);
            }
            $po->refresh();
            $complete = $po->items->every(fn ($i) => $i->received_qty >= $i->qty);
            $po->update(['status' => $complete ? 'received' : 'partially_received', 'received_at' => $complete ? now() : null]);

            return $po;
        });
    }
}
