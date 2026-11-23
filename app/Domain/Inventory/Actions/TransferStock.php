<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Models\StockTransfer;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/** Moves stock between two branches atomically: either every line moves, or none. */
class TransferStock
{
    public function __construct(protected StockLedger $ledger) {}

    /**
     * @param  array<int, int>  $lines  product_variant_id => qty
     */
    public function handle(int $fromBranchId, int $toBranchId, array $lines, ?string $notes = null): StockTransfer
    {
        if ($fromBranchId === $toBranchId) {
            throw new DomainException(__('salon.errors.same_branch_transfer'));
        }

        return DB::transaction(function () use ($fromBranchId, $toBranchId, $lines, $notes) {
            $transfer = StockTransfer::create([
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'number' => 'TR-'.now()->format('ymd').'-'.strtoupper(substr(uniqid(), -5)),
                'status' => 'completed',
                'notes' => $notes,
                'user_id' => auth()->id(),
            ]);

            foreach ($lines as $variantId => $qty) {
                if ($qty <= 0) {
                    continue;
                }
                $transfer->items()->create(['product_variant_id' => $variantId, 'qty' => $qty]);
                $this->ledger->move($fromBranchId, $variantId, -$qty, 'transfer_out', $transfer);
                $this->ledger->move($toBranchId, $variantId, $qty, 'transfer_in', $transfer);
            }

            activity()->performedOn($transfer)->withProperties(['lines' => $lines])->log('stock.transferred');

            return $transfer;
        });
    }
}
