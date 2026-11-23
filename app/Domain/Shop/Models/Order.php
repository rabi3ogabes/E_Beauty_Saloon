<?php

namespace App\Domain\Shop\Models;

use App\Domain\CRM\Models\Client;
use App\Domain\Payments\Contracts\Payable;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shop\Actions\CancelOrder;
use App\Domain\Shop\Actions\ConfirmOrder;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Order extends Model implements Payable
{
    use BelongsToBranch, BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'address' => 'array',
            'gift_wrap' => 'boolean',
            'tax_inclusive' => 'boolean',
            'cod_otp_required' => 'boolean',
            'cod_otp_verified_at' => 'datetime',
            'ready_estimate_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id')->withoutGlobalScopes();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(ShopCoupon::class, 'shop_coupon_id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function balanceDue(): int
    {
        return max(0, (int) $this->total - (int) $this->paid);
    }

    public function isPickup(): bool
    {
        return $this->fulfilment === 'pickup';
    }

    public function isDelivery(): bool
    {
        return in_array($this->fulfilment, ['delivery', 'same_day'], true);
    }

    public function isCod(): bool
    {
        return $this->payment_method === 'cod';
    }

    public function paymentStatus(): string
    {
        return match (true) {
            $this->refunded > 0 && $this->refunded >= $this->paid => 'refunded',
            $this->paid >= $this->total && $this->total > 0 => 'paid',
            $this->paid > 0 => 'partial',
            default => 'unpaid',
        };
    }

    /** Absolute link on the salon's own domain (used in WhatsApp / email updates and payment returns). */
    public function trackingUrl(): string
    {
        $tenant = tenant()?->id === $this->tenant_id ? tenant() : Tenant::find($this->tenant_id);

        return $tenant ? $tenant->url('/shop/order/'.$this->public_token) : route('shop.orders.show', $this->public_token);
    }

    public function paymentDescription(): string
    {
        return __('shop.order.payment_description', ['number' => $this->number]);
    }

    /** Online payment for the remainder succeeded → the order is confirmed (stock deducted). */
    public function onPaymentSucceeded(Payment $payment): void
    {
        $this->asOwnTenant(function () use ($payment) {
            $order = static::withoutGlobalScopes()->lockForUpdate()->findOrFail($this->id);
            $order->increment('paid', $payment->amount);
            if ($order->status === OrderStatus::Pending) {
                app(ConfirmOrder::class)->handle($order, note: 'payment:'.$payment->driver);
            }
        });
    }

    /** Failed / expired online payment → cancel the order and release reserved stock. */
    public function onPaymentFailed(Payment $payment): void
    {
        $this->asOwnTenant(function () {
            $order = static::withoutGlobalScopes()->findOrFail($this->id);
            if ($order->status === OrderStatus::Pending) {
                app(CancelOrder::class)->handle($order, 'payment_failed');
                // Give the client their basket back so they can retry.
                Cart::withoutGlobalScopes()->whereKey($order->cart_id)->where('status', 'converted')->update(['status' => 'active', 'order_id' => null]);
            }
        });
    }

    protected function asOwnTenant(\Closure $callback): mixed
    {
        $ctx = app(TenantContext::class);
        if ($ctx->id() === $this->tenant_id) {
            return $callback();
        }

        return $ctx->run(Tenant::findOrFail($this->tenant_id), $callback);
    }
}
