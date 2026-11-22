<?php

namespace App\Domain\Shop\Actions;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\BranchStock;
use App\Domain\Shop\Support\CheckoutQuote;
use App\Domain\Shop\Support\CouponValidator;
use App\Domain\Shop\Support\FulfilmentPlanner;
use App\Domain\Shop\Support\ShopSettings;
use App\Support\Exceptions\DomainException;

/**
 * Prices a checkout: merchandise, coupon, delivery zone fee / minimum / free-shipping threshold,
 * same-day surcharge, gift wrap and tax (branch rate, product override; inclusive or exclusive).
 *
 * $input: items [[variant_id, qty]], fulfilment, branch_id, zone_id, lat, lng, area, delivery_slot,
 *         coupon_code, gift_wrap, client_id, phone, strict (validate slot + stock).
 */
class QuoteCheckout
{
    public function __construct(protected FulfilmentPlanner $planner, protected CouponValidator $coupons) {}

    public function handle(array $input): CheckoutQuote
    {
        $items = collect($input['items'] ?? [])->filter(fn ($i) => ($i['qty'] ?? 0) > 0);
        if ($items->isEmpty()) {
            throw new DomainException(__('shop.errors.cart_empty'));
        }
        $variants = ProductVariant::with('product')->whereIn('id', $items->pluck('variant_id'))->get()->keyBy('id');

        $lines = [];
        foreach ($items as $i) {
            $variant = $variants[$i['variant_id']] ?? null;
            if (! $variant || ! $variant->product?->is_active || ! $variant->product->sold_online) {
                throw new DomainException(__('shop.errors.not_available'));
            }
            $unit = $variant->effectivePrice();
            $lines[] = ['variant' => $variant, 'qty' => (int) $i['qty'], 'unit_price' => $unit, 'total' => $unit * (int) $i['qty']];
        }
        $subtotal = array_sum(array_column($lines, 'total'));

        $coupon = null;
        $discount = 0;
        if ($code = trim((string) ($input['coupon_code'] ?? ''))) {
            $coupon = $this->coupons->validate($code, $subtotal, $input['client_id'] ?? null, $input['phone'] ?? null);
            $discount = $coupon->discountFor($subtotal);
        }
        $merchandise = $subtotal - $discount;

        $fulfilment = $input['fulfilment'] ?? 'delivery';
        if (! in_array($fulfilment, ['delivery', 'pickup', 'same_day'], true)) {
            throw new DomainException(__('shop.errors.fulfilment_invalid'));
        }
        $zone = null;
        $slots = [];
        $strict = (bool) ($input['strict'] ?? false);
        if ($fulfilment === 'pickup') {
            $branch = $this->planner->pickupBranches()->firstWhere('id', (int) ($input['branch_id'] ?? 0))
                ?? throw new DomainException(__('shop.errors.pickup_branch'));
            $fee = 0;
            $estimate = $this->planner->pickupEstimate($branch);
        } else {
            $zone = $this->planner->resolveZone(
                isset($input['zone_id']) ? (int) $input['zone_id'] : null,
                isset($input['lat']) && $input['lat'] !== '' ? (float) $input['lat'] : null,
                isset($input['lng']) && $input['lng'] !== '' ? (float) $input['lng'] : null,
                $input['area'] ?? null,
            ) ?? throw new DomainException(__('shop.errors.no_zone'));
            $branch = Branch::withoutGlobalScopes()->findOrFail($zone->branch_id);
            $fee = $this->planner->deliveryFee($zone, $fulfilment, $merchandise, (bool) $coupon?->free_shipping);
            if ($fulfilment === 'same_day') {
                $slots = $this->planner->sameDaySlots($zone);
                if ($strict && ! $this->planner->isValidSlot($zone, $input['delivery_slot'] ?? null)) {
                    throw new DomainException(__('shop.errors.slot_invalid'));
                }
            }
            $estimate = $this->planner->deliveryEstimate($zone, $fulfilment, $input['delivery_slot'] ?? null);
        }

        if ($strict) {
            $this->assertStock($branch->id, $lines);
        }

        $wrap = ! empty($input['gift_wrap']) && ShopSettings::get('gift_wrap_enabled') ? (int) ShopSettings::get('gift_wrap_price') : 0;

        $inclusive = (bool) ($branch->tax_inclusive ?? true);
        $tax = 0;
        foreach ($lines as $line) {
            $rate = (float) ($line['variant']->product->tax_percent ?? $branch->tax_percent ?? 0);
            if ($rate <= 0 || $subtotal === 0) {
                continue;
            }
            $net = $line['total'] * $merchandise / $subtotal;
            $tax += (int) round($inclusive ? $net * $rate / (100 + $rate) : $net * $rate / 100);
        }

        $total = $merchandise + $fee + $wrap + ($inclusive ? 0 : $tax);

        return new CheckoutQuote($lines, $fulfilment, $branch, $zone, $coupon, $subtotal, $discount, $fee, $wrap, $tax, $inclusive, $total, $estimate, $slots);
    }

    protected function assertStock(int $branchId, array $lines): void
    {
        foreach ($lines as $line) {
            if (! $line['variant']->product->track_stock) {
                continue;
            }
            $available = BranchStock::withoutGlobalScopes()->where('branch_id', $branchId)->where('product_variant_id', $line['variant']->id)->first()?->available() ?? 0;
            if ($available < $line['qty']) {
                throw new DomainException(__('shop.errors.only_left_branch', ['count' => $available, 'item' => $line['variant']->displayName()]));
            }
        }
    }
}
