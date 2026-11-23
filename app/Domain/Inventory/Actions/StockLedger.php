<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\BranchStock;
use App\Domain\Inventory\Models\StockMovement;
use App\Support\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY way to change stock. Every change writes an immutable movement row
 * and updates the branch_stock balance inside the same transaction.
 */
class StockLedger
{
    public const TYPES = ['purchase', 'sale', 'return', 'transfer_in', 'transfer_out', 'adjustment', 'used_in_service', 'order'];

    /**
     * @param  int  $qty  Signed quantity (negative = out).
     */
    public function move(
        int $branchId,
        ProductVariant|int $variant,
        int $qty,
        string $type,
        ?Model $reference = null,
        ?string $idempotencyKey = null,
        ?string $note = null,
        bool $allowNegative = false,
        ?int $unitCost = null,
    ): StockMovement {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown stock movement type [$type]");
        }
        $variantId = $variant instanceof ProductVariant ? $variant->id : $variant;

        return DB::transaction(function () use ($branchId, $variantId, $qty, $type, $reference, $idempotencyKey, $note, $allowNegative, $unitCost) {
            if ($idempotencyKey && ($existing = StockMovement::withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }

            $stock = BranchStock::withoutGlobalScopes()
                ->where('branch_id', $branchId)->where('product_variant_id', $variantId)
                ->lockForUpdate()->first()
                ?? BranchStock::withoutGlobalScopes()->create([
                    'tenant_id' => tenant()?->id ?? ProductVariant::withoutGlobalScopes()->findOrFail($variantId)->tenant_id,
                    'branch_id' => $branchId, 'product_variant_id' => $variantId, 'qty' => 0,
                ]);

            $newQty = $stock->qty + $qty;
            if ($newQty < 0 && ! $allowNegative) {
                $name = ProductVariant::withoutGlobalScopes()->with('product')->find($variantId)?->displayName();
                throw new DomainException(__('salon.errors.insufficient_stock', ['item' => $name, 'available' => $stock->qty]));
            }

            $stock->qty = $newQty;
            $stock->save();

            return StockMovement::withoutGlobalScopes()->create([
                'tenant_id' => $stock->tenant_id,
                'branch_id' => $branchId,
                'product_variant_id' => $variantId,
                'type' => $type,
                'qty' => $qty,
                'balance_after' => $newQty,
                'unit_cost' => $unitCost,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'idempotency_key' => $idempotencyKey,
                'note' => $note,
                'user_id' => auth()->id(),
            ]);
        });
    }

    /** Reserve stock for a pending shop order (does not change on-hand qty). */
    public function reserve(int $branchId, int $variantId, int $qty): void
    {
        DB::transaction(function () use ($branchId, $variantId, $qty) {
            $stock = BranchStock::withoutGlobalScopes()->where('branch_id', $branchId)
                ->where('product_variant_id', $variantId)->lockForUpdate()->first();
            if (! $stock || $stock->qty - $stock->reserved < $qty) {
                throw new DomainException(__('salon.errors.insufficient_stock', ['item' => '#'.$variantId, 'available' => $stock?->available() ?? 0]));
            }
            $stock->increment('reserved', $qty);
        });
    }

    public function release(int $branchId, int $variantId, int $qty): void
    {
        DB::transaction(function () use ($branchId, $variantId, $qty) {
            $stock = BranchStock::withoutGlobalScopes()->where('branch_id', $branchId)
                ->where('product_variant_id', $variantId)->lockForUpdate()->first();
            $stock?->update(['reserved' => max(0, $stock->reserved - $qty)]);
        });
    }
}
