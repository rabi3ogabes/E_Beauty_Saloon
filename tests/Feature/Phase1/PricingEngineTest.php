<?php

use App\Domain\Billing\Actions\CalculatePrice;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\Plan;
use App\Support\Exceptions\DomainException;

it('prices a tenant with 3 branches + POS + Booking for 6 months correctly', function () {
    // Essential: 299.00/mo, 1 branch included, 149.00 per extra branch, includes website + booking.
    // POS is an add-on priced per branch at 99.00/mo. 6-month cycle = 10% discount.
    $plan = Plan::where('slug', 'essential')->first();
    $quote = app(CalculatePrice::class)->handle($plan, 6, 3, ['pos', 'booking']);

    $planLine = 29900 * 6;            // 179,400
    $branches = 2 * 14900 * 6;        // 178,800
    $pos = 9900 * 3 * 6;              // 178,200 (per branch)
    $subtotal = $planLine + $branches + $pos; // booking already included -> not charged
    $discount = (int) round($subtotal * 0.10);

    expect($quote->lines)->toHaveCount(3)
        ->and($quote->subtotal)->toBe($subtotal)
        ->and($quote->cycleDiscount)->toBe($discount)
        ->and($quote->total)->toBe($subtotal - $discount)
        ->and($quote->modules)->toContain('pos', 'booking', 'website');
});

it('applies a first-cycle coupon after the cycle discount', function () {
    $plan = Plan::where('slug', 'essential')->first();
    Coupon::create(['code' => 'half', 'type' => 'percent', 'value' => 5000, 'applies_to' => 'first_cycle']);

    $first = app(CalculatePrice::class)->handle($plan, 12, 1, [], 'HALF');
    $renewal = app(CalculatePrice::class)->handle($plan, 12, 1, [], 'HALF', isFirstCycle: false);

    $afterCycle = 29900 * 12 - (int) round(29900 * 12 * 0.20);
    expect($first->couponDiscount)->toBe((int) round($afterCycle * 0.5))
        ->and($renewal->couponDiscount)->toBe(0);
});

it('rejects expired coupons and unknown cycles', function () {
    $plan = Plan::where('slug', 'essential')->first();
    Coupon::create(['code' => 'OLD', 'type' => 'fixed', 'value' => 1000, 'expires_at' => now()->subDay()]);

    expect(fn () => app(CalculatePrice::class)->handle($plan, 6, 1, [], 'OLD'))->toThrow(DomainException::class)
        ->and(fn () => app(CalculatePrice::class)->handle($plan, 5))->toThrow(DomainException::class);
});
