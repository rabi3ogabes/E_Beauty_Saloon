<?php

namespace App\Domain\Shop\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\Loyalty\Actions\LoyaltyLedger;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Order;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * out_for_delivery | ready_for_pickup → delivered. Collects the COD balance (records a cash / card
 * Payment) and earns loyalty points for the client.
 */
class MarkDelivered
{
    public const COD_METHODS = ['cash', 'card_terminal'];

    public function __construct(protected OrderTransition $transition, protected LoyaltyLedger $loyalty) {}

    public function handle(Order $order, ?string $codMethod = null, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $codMethod, $note) {
            $order = $this->transition->lock($order);
            $this->transition->assertCan($order, OrderStatus::Delivered);

            $due = $order->balanceDue();
            if ($due > 0) {
                if (! $order->isCod()) {
                    throw new DomainException(__('shop.errors.unpaid'));
                }
                $method = in_array($codMethod ?? $order->cod_method, self::COD_METHODS, true) ? ($codMethod ?? $order->cod_method) : 'cash';
                Payment::withoutGlobalScopes()->firstOrCreate(['idempotency_key' => "order:{$order->id}:cod"], [
                    'tenant_id' => $order->tenant_id,
                    'branch_id' => $order->branch_id,
                    'client_id' => $order->client_id,
                    'payable_type' => $order->getMorphClass(),
                    'payable_id' => $order->id,
                    'method' => $method,
                    'driver' => 'cod',
                    'purpose' => 'payment',
                    'status' => 'succeeded',
                    'amount' => $due,
                    'currency' => $order->currency,
                    'paid_at' => now(),
                    'meta' => ['collected_by' => auth('web')->id(), 'driver_id' => $order->driver_id],
                ]);
                $order->forceFill(['paid' => $order->paid + $due, 'cod_method' => $method]);
            }

            $order = $this->transition->apply($order, OrderStatus::Delivered, $note);

            if ($order->client_id && tenant()?->hasModule('loyalty')) {
                $client = Client::withoutGlobalScopes()->find($order->client_id);
                if ($client) {
                    $this->loyalty->earnForSpend($client, max(0, $order->total - $order->delivery_fee - $order->refunded), $order, "order:{$order->id}:earn");
                }
            }

            return $order;
        });
    }
}
