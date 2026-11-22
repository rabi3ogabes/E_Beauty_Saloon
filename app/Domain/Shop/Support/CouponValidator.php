<?php

namespace App\Domain\Shop\Support;

use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Order;
use App\Domain\Shop\Models\ShopCoupon;
use App\Support\Exceptions\DomainException;
use App\Support\Tenancy\BranchScope;

/** Shop coupon rules: active window, total uses, per-client uses and minimum order. */
class CouponValidator
{
    public function validate(string $code, int $subtotal, ?int $clientId = null, ?string $phone = null): ShopCoupon
    {
        $coupon = ShopCoupon::where('code', strtoupper(trim($code)))->first();

        if (! $coupon || ! $coupon->is_active
            || ($coupon->starts_at && $coupon->starts_at->isFuture())
            || ($coupon->expires_at && $coupon->expires_at->isPast())) {
            throw new DomainException(__('shop.errors.coupon_invalid'));
        }
        if ($coupon->max_uses !== null && $coupon->uses_count >= $coupon->max_uses) {
            throw new DomainException(__('shop.errors.coupon_used_up'));
        }
        if ($subtotal < (int) $coupon->min_order) {
            throw new DomainException(__('shop.errors.coupon_min_order', ['amount' => money((int) $coupon->min_order)]));
        }
        if ($coupon->max_uses_per_client !== null && ($clientId || $phone)) {
            $used = Order::withoutGlobalScope(BranchScope::class)->where('shop_coupon_id', $coupon->id)
                ->where('status', '!=', OrderStatus::Cancelled->value)
                ->where(fn ($q) => $q->when($clientId, fn ($w) => $w->where('client_id', $clientId))->when($phone, fn ($w) => $w->orWhere('customer_phone', $phone)))
                ->count();
            if ($used >= $coupon->max_uses_per_client) {
                throw new DomainException(__('shop.errors.coupon_client_limit'));
            }
        }

        return $coupon;
    }
}
