<?php

namespace App\Domain\Shop\Events;

use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired after every order status change (including the initial "pending" on placement). */
class OrderStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order, public ?OrderStatus $from, public OrderStatus $to, public ?string $note = null) {}
}
