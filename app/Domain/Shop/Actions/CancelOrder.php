<?php

namespace App\Domain\Shop\Actions;

use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Payments\Actions\RefundPayment;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Order;
use App\Domain\Shop\Models\ShopCoupon;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an open order: releases the reservation (or returns deducted stock to the branch),
 * refunds every collected payment to its original method (or as store credit) and frees the coupon use.
 */
class CancelOrder
{
    public function __construct(protected OrderTransition $transition, protected StockLedger $stock, protected RefundPayment $refunds) {}

    public function handle(Order $order, ?string $reason = null, bool $asStoreCredit = false): Order
    {
        return DB::transaction(function () use ($order, $reason, $asStoreCredit) {
            $order = $this->transition->lock($order);
            $this->transition->assertCan($order, OrderStatus::Cancelled);

            $stockState = $order->stock_state;
            foreach ($order->items as $item) {
                if (! $item->product_variant_id) {
                    continue;
                }
                if ($stockState === 'reserved') {
                    $this->stock->release($order->branch_id, $item->product_variant_id, $item->qty);
                } elseif ($stockState === 'deducted') {
                    $this->stock->move($order->branch_id, $item->product_variant_id, $item->qty, 'return', $order, "order:{$order->id}:item:{$item->id}:cancel", $order->number.' cancelled');
                }
            }

            $refunded = $this->refundAll($order, $asStoreCredit, $reason);

            if ($order->shop_coupon_id) {
                ShopCoupon::withoutGlobalScopes()->whereKey($order->shop_coupon_id)->where('uses_count', '>', 0)->decrement('uses_count');
            }

            return $this->transition->apply($order, OrderStatus::Cancelled, $reason ? __('shop.cancel_reasons.'.$reason) : null, [
                'stock_state' => match ($stockState) {
                    'reserved' => 'released', 'deducted' => 'returned', default => $stockState
                },
                'cancel_reason' => $reason,
                'refunded' => $order->refunded + $refunded,
            ]);
        });
    }

    /** Refunds all collected payments of an order. Returns the refunded amount. */
    public function refundAll(Order $order, bool $asStoreCredit = false, ?string $reason = null): int
    {
        $total = 0;
        $payments = Payment::withoutGlobalScopes()->where('payable_type', $order->getMorphClass())->where('payable_id', $order->id)
            ->where('purpose', 'payment')->whereIn('status', ['succeeded', 'partially_refunded'])->get();
        foreach ($payments as $payment) {
            $amount = $payment->refundable();
            if ($amount <= 0) {
                continue;
            }
            $this->refunds->handle($payment, $amount, $asStoreCredit && $payment->client_id !== null, $reason ?? 'order_cancelled');
            $total += $amount;
        }

        return $total;
    }
}
