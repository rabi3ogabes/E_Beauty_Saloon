<?php

namespace App\Domain\POS\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Payments\Actions\RefundPayment;
use App\Domain\Payments\Actions\WalletLedger;
use App\Domain\POS\Enums\SaleStatus;
use App\Domain\POS\Models\Commission;
use App\Domain\POS\Models\Sale;
use App\Domain\POS\Models\SaleRefund;
use App\Domain\POS\Models\Shift;
use App\Models\User;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Full or partial refund (and void) of a completed sale:
 * money back to the original methods (RefundPayment) or as store credit, stock returned (`return`),
 * commissions reversed, client lifetime value reduced, everything logged.
 */
class RefundSale
{
    public function __construct(protected RefundPayment $refundPayment, protected WalletLedger $wallet, protected StockLedger $stock) {}

    /**
     * @param  array<int, int>|null  $items  sale_item_id => qty (null = everything still refundable)
     * @param  User  $authorizedBy  the cashier (if permitted) or the manager who entered their PIN
     */
    public function handle(Sale $sale, ?array $items, bool $storeCredit, ?string $reason, User $user, User $authorizedBy, string $kind = 'refund', ?Shift $shift = null): SaleRefund
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        $permission = $kind === 'void' ? 'pos.void' : 'pos.refund';
        if ($authorizedBy->tenant_id !== $sale->tenant_id || ! $authorizedBy->can($permission)) {
            throw new DomainException(__('pos.errors.manager_pin_required'));
        }

        return DB::transaction(function () use ($sale, $items, $storeCredit, $reason, $user, $authorizedBy, $kind, $shift) {
            $sale = Sale::withoutGlobalScopes()->with(['items', 'salePayments.payment', 'shift'])->lockForUpdate()->findOrFail($sale->id);
            if (! in_array($sale->status, [SaleStatus::Completed, SaleStatus::PartiallyRefunded], true)) {
                throw new DomainException(__('pos.errors.not_refundable'));
            }
            if ($kind === 'void' && ($sale->refunded_total > 0 || $sale->items->sum('refunded_qty') > 0)) {
                throw new DomainException(__('pos.errors.void_after_refund'));
            }
            if ($storeCredit && ! $sale->client_id) {
                throw new DomainException(__('salon.errors.refund_needs_client'));
            }

            // 1. Which units, which amount.
            $selection = [];
            foreach ($sale->items as $item) {
                $qty = $items === null || $kind === 'void' ? $item->refundableQty() : min((int) ($items[$item->id] ?? 0), $item->refundableQty());
                if ($qty > 0) {
                    $selection[] = ['item' => $item, 'qty' => $qty, 'amount' => $item->amountFor($qty)];
                }
            }
            if (! $selection) {
                throw new DomainException(__('pos.errors.nothing_to_refund'));
            }
            $selectedQty = collect($selection)->mapWithKeys(fn ($s) => [$s['item']->id => $s['qty']]);
            $everything = $sale->items->every(fn ($i) => $i->refundableQty() === (int) ($selectedQty[$i->id] ?? 0));
            $amount = $everything ? $sale->refundableAmount() : min($sale->refundableAmount(), (int) collect($selection)->sum('amount'));

            $refund = SaleRefund::create([
                'sale_id' => $sale->id,
                'pos_shift_id' => ($shift?->isOpen() ? $shift : ($sale->shift?->isOpen() ? $sale->shift : null))?->id,
                'kind' => $kind,
                'items' => collect($selection)->map(fn ($s) => ['sale_item_id' => $s['item']->id, 'name' => $s['item']->name, 'qty' => $s['qty'], 'amount' => $s['amount']])->values()->all(),
                'amount' => $amount,
                'method' => $storeCredit ? 'store_credit' : 'original',
                'reason' => $reason,
                'user_id' => $user->id,
                'approved_by' => $authorizedBy->id,
            ]);

            // 2. Money: newest payments first, back to their method (or store credit).
            $remaining = $amount;
            $breakdown = [];
            $cash = 0;
            foreach ($sale->salePayments->sortByDesc('id') as $sp) {
                if ($remaining <= 0) {
                    break;
                }
                $payment = $sp->payment;
                $part = $payment ? min($remaining, $payment->refundable(), (int) $sp->amount) : min($remaining, (int) $sp->amount);
                if ($part <= 0) {
                    continue;
                }
                if ($payment) {
                    $this->refundPayment->handle($payment, $part, $storeCredit, $reason);
                } elseif ($storeCredit) {
                    $this->wallet->credit(Client::withoutGlobalScopes()->findOrFail($sale->client_id), $part, 'store_credit', $refund, "sale-refund:{$refund->id}:{$sp->id}");
                }
                $method = $storeCredit ? 'store_credit' : ($sp->method === 'deposit' ? ($payment?->method ?? 'cash') : $sp->method);
                if ($method === 'cash') {
                    $cash += $part;
                }
                $breakdown[$method] = ($breakdown[$method] ?? 0) + $part;
                $remaining -= $part;
            }
            if ($remaining > 0) { // rounding residue / manual deposit: settled at the counter
                $method = $storeCredit ? 'store_credit' : 'cash';
                if ($storeCredit) {
                    $this->wallet->credit(Client::withoutGlobalScopes()->findOrFail($sale->client_id), $remaining, 'store_credit', $refund, "sale-refund:{$refund->id}:rest");
                } else {
                    $cash += $remaining;
                }
                $breakdown[$method] = ($breakdown[$method] ?? 0) + $remaining;
            }
            $refund->update(['breakdown' => $breakdown, 'cash_amount' => $cash]);

            // 3. Stock back on the shelf, commissions reversed, quantities.
            foreach ($selection as $s) {
                $item = $s['item'];
                if ($item->type === 'product' && $item->sellable_id) {
                    $this->stock->move($sale->branch_id, (int) $item->sellable_id, $s['qty'], 'return', $refund, "refund:{$refund->id}:item:{$item->id}");
                }
                foreach (Commission::withoutGlobalScopes()->where('sale_item_id', $item->id)->whereNull('reversal_of_id')->where('amount', '>', 0)->get() as $commission) {
                    Commission::withoutGlobalScopes()->create([
                        'tenant_id' => $commission->tenant_id, 'branch_id' => $commission->branch_id, 'sale_id' => $sale->id,
                        'sale_item_id' => $item->id, 'staff_id' => $commission->staff_id, 'commission_rule_id' => $commission->commission_rule_id,
                        'base_amount' => -intdiv($commission->base_amount * $s['qty'], max(1, $item->qty)),
                        'amount' => -intdiv($commission->amount * $s['qty'], max(1, $item->qty)),
                        'rule_snapshot' => $commission->rule_snapshot, 'period' => now()->format('Y-m'), 'reversal_of_id' => $commission->id,
                    ]);
                }
                $item->increment('refunded_qty', $s['qty']);
            }

            // 4. Sale status + client stats.
            $refundedTotal = (int) $sale->refunded_total + $amount;
            $fully = $refundedTotal >= (int) $sale->total;
            $sale->forceFill([
                'refunded_total' => $refundedTotal,
                'status' => $kind === 'void' ? SaleStatus::Voided : ($fully ? SaleStatus::Refunded : SaleStatus::PartiallyRefunded),
                'voided_at' => $kind === 'void' ? now() : $sale->voided_at,
                'voided_by' => $kind === 'void' ? $authorizedBy->id : $sale->voided_by,
            ])->save();
            if ($sale->client_id && ($client = Client::withoutGlobalScopes()->lockForUpdate()->find($sale->client_id))) {
                $client->forceFill(['lifetime_value' => max(0, $client->lifetime_value - $amount)])->save();
            }

            activity()->performedOn($sale)->causedBy($user)->withProperties([
                'refund_id' => $refund->id, 'amount' => $amount, 'method' => $refund->method, 'reason' => $reason, 'approved_by' => $authorizedBy->id,
            ])->log($kind === 'void' ? 'pos.sale.voided' : 'pos.sale.refunded');

            return $refund->fresh();
        });
    }
}
