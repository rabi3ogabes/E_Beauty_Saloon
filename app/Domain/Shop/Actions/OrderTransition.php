<?php

namespace App\Domain\Shop\Actions;

use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Events\OrderStatusChanged;
use App\Domain\Shop\Models\Order;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Shared state machine step used by every lifecycle Action: validates the transition, stamps timestamps,
 * writes order_status_history + the activity log, and fires OrderStatusChanged after commit.
 */
class OrderTransition
{
    public function lock(Order $order): Order
    {
        return Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
    }

    public function assertCan(Order $order, OrderStatus $to): void
    {
        if (! $order->status->canTransitionTo($to)) {
            throw new DomainException(__('shop.errors.transition', ['from' => $order->status->label(), 'to' => $to->label()]));
        }
    }

    public function apply(Order $order, OrderStatus $to, ?string $note = null, array $attributes = []): Order
    {
        $from = $order->status;
        $stamp = match ($to) {
            OrderStatus::Confirmed => ['confirmed_at' => now()],
            OrderStatus::Delivered => ['delivered_at' => now()],
            OrderStatus::Cancelled => ['cancelled_at' => now()],
            OrderStatus::Returned => ['returned_at' => now()],
            default => [],
        };
        $order->forceFill(array_merge($attributes, $stamp, ['status' => $to]))->save();

        $order->history()->create(['from_status' => $from?->value, 'to_status' => $to->value, 'user_id' => auth('web')->id(), 'note' => $note ? mb_substr($note, 0, 250) : null]);
        activity()->performedOn($order)->withProperties(['from' => $from?->value, 'to' => $to->value, 'note' => $note])->log('order.'.$to->value);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        DB::afterCommit(fn () => OrderStatusChanged::dispatch($fresh, $from, $to, $note));

        return $fresh;
    }
}
