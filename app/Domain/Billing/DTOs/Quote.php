<?php

namespace App\Domain\Billing\DTOs;

/**
 * Result of the price calculator: a transparent line-item breakdown.
 * All amounts are integer minor units.
 */
final class Quote implements \JsonSerializable
{
    /** @param list<array{type:string,description:string,quantity:int,unit_amount:int,amount:int,meta?:array}> $lines */
    public function __construct(
        public readonly string $currency,
        public readonly int $cycleMonths,
        public readonly int $branches,
        public readonly array $modules,
        public readonly array $lines,
        public readonly int $subtotal,
        public readonly int $cycleDiscount,
        public readonly float $cycleDiscountPercent,
        public readonly int $couponDiscount,
        public readonly float $taxPercent,
        public readonly int $tax,
        public readonly int $total,
        public readonly ?string $couponCode = null,
    ) {}

    public function discountTotal(): int
    {
        return $this->cycleDiscount + $this->couponDiscount;
    }

    public function monthlyEquivalent(): int
    {
        return (int) round($this->total / max(1, $this->cycleMonths));
    }

    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'cycle_months' => $this->cycleMonths,
            'branches' => $this->branches,
            'modules' => $this->modules,
            'lines' => $this->lines,
            'subtotal' => $this->subtotal,
            'cycle_discount' => $this->cycleDiscount,
            'cycle_discount_percent' => $this->cycleDiscountPercent,
            'coupon_discount' => $this->couponDiscount,
            'coupon_code' => $this->couponCode,
            'tax_percent' => $this->taxPercent,
            'tax' => $this->tax,
            'total' => $this->total,
            'monthly_equivalent' => $this->monthlyEquivalent(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
