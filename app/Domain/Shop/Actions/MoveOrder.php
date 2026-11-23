<?php

namespace App\Domain\Shop\Actions;

use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Driver;
use App\Domain\Shop\Models\Order;
use App\Support\Exceptions\DomainException;

/**
 * Moves an order to a target status by running the matching lifecycle Action (used by the
 * kanban board drag & drop and the order resource). Invalid moves throw a DomainException.
 */
class MoveOrder
{
    public function handle(Order $order, OrderStatus|string $to, array $data = []): Order
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        $to = $to instanceof OrderStatus ? $to : (OrderStatus::tryFrom($to) ?? throw new DomainException(__('shop.errors.transition', ['from' => $order->status->label(), 'to' => $to])));

        if ($order->status === $to) {
            return $order;
        }
        if (! $order->status->canTransitionTo($to)) {
            throw new DomainException(__('shop.errors.transition', ['from' => $order->status->label(), 'to' => $to->label()]));
        }

        return match ($to) {
            OrderStatus::Confirmed => app(ConfirmOrder::class)->handle($order, $data['note'] ?? null),
            OrderStatus::Preparing => app(MarkPreparing::class)->handle($order, $data['note'] ?? null),
            OrderStatus::ReadyForPickup => app(MarkReadyForPickup::class)->handle($order, $data['note'] ?? null),
            OrderStatus::OutForDelivery => app(MarkOutForDelivery::class)->handle($order, ! empty($data['driver_id']) ? Driver::findOrFail($data['driver_id']) : null, $data['note'] ?? null),
            OrderStatus::Delivered => app(MarkDelivered::class)->handle($order, $data['cod_method'] ?? null, $data['note'] ?? null),
            OrderStatus::Cancelled => app(CancelOrder::class)->handle($order, $data['reason'] ?? 'salon', (bool) ($data['store_credit'] ?? false)),
            OrderStatus::Returned => app(ReturnOrder::class)->handle($order, (bool) ($data['store_credit'] ?? false), (bool) ($data['restock'] ?? true), $data['note'] ?? null),
            OrderStatus::Pending => throw new DomainException(__('shop.errors.transition', ['from' => $order->status->label(), 'to' => $to->label()])),
        };
    }
}
