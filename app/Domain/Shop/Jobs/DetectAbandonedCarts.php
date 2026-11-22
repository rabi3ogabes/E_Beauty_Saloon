<?php

namespace App\Domain\Shop\Jobs;

use App\Domain\Shop\Events\AbandonedCartDetected;
use App\Domain\Shop\Models\Cart;
use App\Domain\Shop\Support\ShopSettings;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Emits AbandonedCartDetected once per idle period for active carts with items and a known
 * client or phone, idle longer than the threshold (default 2 hours). Marketing hooks automations to it.
 */
class DetectAbandonedCarts implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public ?int $tenantId = null) {}

    public function handle(TenantContext $context): void
    {
        $tenants = Tenant::query()->when($this->tenantId, fn ($q) => $q->whereKey($this->tenantId))->get()->filter->hasModule('shop');

        foreach ($tenants as $tenant) {
            $context->run($tenant, function () use ($tenant) {
                $idle = now()->subMinutes((int) ShopSettings::get('abandoned_after_minutes'));
                Cart::query()->where('status', 'active')->whereNull('abandoned_notified_at')
                    ->where('last_activity_at', '<', $idle)
                    ->where(fn ($q) => $q->whereNotNull('client_id')->orWhereNotNull('phone'))
                    ->whereHas('items')
                    ->with(['items.variant.product', 'client'])
                    ->each(function (Cart $cart) use ($tenant) {
                        $cart->forceFill(['abandoned_notified_at' => now()])->save();
                        AbandonedCartDetected::dispatch(
                            $cart,
                            $tenant->id,
                            $cart->contactPhone(),
                            $cart->client_id,
                            (int) $cart->items->sum(fn ($i) => $i->lineTotal()),
                            $tenant->url('/shop/cart/recover/'.$cart->token),
                        );
                    });
            });
        }
    }
}
