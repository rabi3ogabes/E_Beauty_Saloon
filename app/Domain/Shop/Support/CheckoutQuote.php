<?php

namespace App\Domain\Shop\Support;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Shop\Models\DeliveryZone;
use App\Domain\Shop\Models\ShopCoupon;
use Carbon\CarbonImmutable;

/** Priced checkout: lines + totals for a fulfilment choice. All money in minor units. */
final class CheckoutQuote
{
    /**
     * @param  list<array{variant: ProductVariant, qty: int, unit_price: int, total: int}>  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly string $fulfilment,
        public readonly Branch $branch,
        public readonly ?DeliveryZone $zone,
        public readonly ?ShopCoupon $coupon,
        public readonly int $subtotal,
        public readonly int $discount,
        public readonly int $deliveryFee,
        public readonly int $giftWrapFee,
        public readonly int $tax,
        public readonly bool $taxInclusive,
        public readonly int $total,
        public readonly ?CarbonImmutable $readyEstimate,
        public readonly array $slots = [],
    ) {}

    public function toArray(): array
    {
        return [
            'fulfilment' => $this->fulfilment,
            'branch_id' => $this->branch->id,
            'branch' => $this->branch->name,
            'zone_id' => $this->zone?->id,
            'zone' => $this->zone?->name,
            'coupon' => $this->coupon?->code,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'delivery_fee' => $this->deliveryFee,
            'gift_wrap_fee' => $this->giftWrapFee,
            'tax' => $this->tax,
            'tax_inclusive' => $this->taxInclusive,
            'total' => $this->total,
            'ready_estimate' => $this->readyEstimate?->setTimezone($this->branch->timezone ?: 'Asia/Qatar')->translatedFormat('D j M, H:i'),
            'slots' => $this->slots,
            'free_shipping_gap' => $this->zone && $this->zone->free_shipping_threshold && $this->fulfilment === 'delivery'
                ? max(0, (int) $this->zone->free_shipping_threshold - ($this->subtotal - $this->discount)) : null,
            'formatted' => [
                'subtotal' => money($this->subtotal),
                'discount' => money($this->discount),
                'delivery_fee' => money($this->deliveryFee),
                'gift_wrap_fee' => money($this->giftWrapFee),
                'tax' => money($this->tax),
                'total' => money($this->total),
            ],
        ];
    }
}
