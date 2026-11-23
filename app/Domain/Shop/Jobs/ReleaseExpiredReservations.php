<?php

namespace App\Domain\Shop\Jobs;

use App\Domain\Payments\Actions\SettlePayment;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shop\Actions\CancelOrder;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Order;
use App\Domain\Shop\Support\ShopSettings;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Unpaid online orders older than the reservation window: fail their pending payment (which cancels the
 * order and releases the reserved stock through Order::onPaymentFailed).
 */
class ReleaseExpiredReservations implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public ?int $tenantId = null) {}

    public function handle(TenantContext $context, SettlePayment $settle, CancelOrder $cancel): void
    {
        $tenants = Tenant::query()->when($this->tenantId, fn ($q) => $q->whereKey($this->tenantId))->get()->filter->hasModule('shop');

        foreach ($tenants as $tenant) {
            $context->run($tenant, function () use ($settle, $cancel) {
                $cutoff = now()->subMinutes((int) ShopSettings::get('reservation_minutes'));
                Order::withoutGlobalScopes()->where('tenant_id', tenant()->id)->where('status', OrderStatus::Pending->value)
                    ->where('payment_method', 'online')->where('created_at', '<', $cutoff)
                    ->each(function (Order $order) use ($settle, $cancel) {
                        $pending = Payment::withoutGlobalScopes()->where('payable_type', $order->getMorphClass())->where('payable_id', $order->id)
                            ->where('status', 'pending')->get();
                        foreach ($pending as $payment) {
                            $settle->fail($payment, 'expired');
                        }
                        $order->refresh();
                        if ($order->status === OrderStatus::Pending) {
                            $cancel->handle($order, 'payment_expired');
                        }
                    });
            });
        }
    }
}
