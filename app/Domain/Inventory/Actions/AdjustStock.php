<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Models\BranchStock;
use App\Domain\Inventory\Models\StockMovement;
use App\Support\Exceptions\DomainException;

/**
 * Manual stock adjustment (count correction, damage, gift...). Goes through the ledger:
 * mode "delta" adds/removes qty, mode "set" counts the shelf and moves the difference.
 */
class AdjustStock
{
    public function __construct(protected StockLedger $ledger) {}

    public function handle(int $branchId, int $variantId, int $qty, string $mode = 'delta', ?string $note = null): ?StockMovement
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));

        $delta = $qty;
        if ($mode === 'set') {
            if ($qty < 0) {
                throw new DomainException(__('workspace.inventory.count_negative'));
            }
            $current = (int) BranchStock::withoutGlobalScopes()
                ->where('tenant_id', tenant()?->id)->where('branch_id', $branchId)->where('product_variant_id', $variantId)->value('qty');
            $delta = $qty - $current;
        }
        if ($delta === 0) {
            return null;
        }

        return $this->ledger->move($branchId, $variantId, $delta, 'adjustment', note: $note ?: __('workspace.inventory.manual_adjustment'));
    }
}
