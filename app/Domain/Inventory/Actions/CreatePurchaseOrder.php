<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Models\PurchaseOrder;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/** Creates a draft purchase order with its lines; total = sum(qty x unit_cost). Stock moves only on receive. */
class CreatePurchaseOrder
{
    /** @param  list<array{product_variant_id:int, qty:int, unit_cost:int}>  $items */
    public function handle(int $branchId, ?int $supplierId, array $items, ?string $expectedAt = null, ?string $notes = null): PurchaseOrder
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));

        $items = collect($items)->filter(fn ($i) => ($i['product_variant_id'] ?? null) && (int) ($i['qty'] ?? 0) > 0)->values();
        if ($items->isEmpty()) {
            throw new DomainException(__('workspace.inventory.po_needs_items'));
        }

        return DB::transaction(function () use ($branchId, $supplierId, $items, $expectedAt, $notes) {
            $po = PurchaseOrder::create([
                'branch_id' => $branchId,
                'supplier_id' => $supplierId,
                'number' => 'PO-'.now()->format('ymd').'-'.strtoupper(substr(uniqid(), -5)),
                'status' => 'draft',
                'expected_at' => $expectedAt,
                'notes' => $notes,
                'total' => $items->sum(fn ($i) => (int) $i['qty'] * (int) ($i['unit_cost'] ?? 0)),
            ]);
            foreach ($items as $i) {
                $po->items()->create(['product_variant_id' => (int) $i['product_variant_id'], 'qty' => (int) $i['qty'], 'unit_cost' => (int) ($i['unit_cost'] ?? 0)]);
            }
            activity()->performedOn($po)->causedBy(auth()->user())->log('purchase_order.created');

            return $po;
        });
    }
}
