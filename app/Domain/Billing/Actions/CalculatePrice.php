<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\DTOs\Quote;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\Module;
use App\Domain\Billing\Models\Plan;
use App\Support\Exceptions\DomainException;

/**
 * (plan + extra branches + extra modules) x cycle months - cycle discount - coupon (+ tax) = total
 */
class CalculatePrice
{
    /**
     * @param  list<string>  $extraModules  Module keys on top of what the plan includes.
     */
    public function handle(
        Plan $plan,
        int $cycleMonths,
        int $branches = 1,
        array $extraModules = [],
        Coupon|string|null $coupon = null,
        float $taxPercent = 0,
        bool $isFirstCycle = true,
    ): Quote {
        if (! in_array($cycleMonths, config('salon.billing_cycles'), true)) {
            throw new DomainException(__('salon.errors.invalid_cycle'));
        }
        $branches = max(1, $branches);
        $currency = $plan->currency;
        $lines = [];

        $lines[] = $this->line('plan', __('salon.billing.line_plan', ['plan' => $plan->name, 'months' => $cycleMonths]), $cycleMonths, $plan->monthly_price);

        $extraBranches = max(0, $branches - $plan->included_branches);
        if ($extraBranches > 0) {
            $lines[] = $this->line('extra_branch', __('salon.billing.line_extra_branches', ['count' => $extraBranches, 'months' => $cycleMonths]),
                $extraBranches * $cycleMonths, $plan->extra_branch_price, ['branches' => $extraBranches]);
        }

        $extraModules = array_values(array_diff(array_unique($extraModules), $plan->included_modules ?? []));
        $modules = Module::query()->with('prices')->whereIn('key', $extraModules)->where('is_active', true)->get()->keyBy('key');
        foreach ($extraModules as $key) {
            $module = $modules->get($key) ?? throw new DomainException(__('salon.errors.unknown_module', ['module' => $key]));
            $price = $module->priceFor($plan);
            if (! $price) {
                continue;
            }
            $perBranch = $price->per_branch || $module->pricing_mode === 'per_branch';
            $units = ($perBranch ? $branches : 1) * $cycleMonths;
            $label = $perBranch
                ? __('salon.billing.line_module_branch', ['module' => $module->name, 'branches' => $branches, 'months' => $cycleMonths])
                : __('salon.billing.line_module', ['module' => $module->name, 'months' => $cycleMonths]);
            $lines[] = $this->line('module', $label, $units, $price->price_per_month, ['module' => $key, 'per_branch' => $perBranch]);
        }

        $subtotal = array_sum(array_column($lines, 'amount'));

        $discountPercent = $plan->discountForCycle($cycleMonths);
        $cycleDiscount = (int) round($subtotal * $discountPercent / 100);

        $couponDiscount = 0;
        $couponCode = null;
        if ($coupon) {
            $coupon = $coupon instanceof Coupon ? $coupon : Coupon::where('code', strtoupper(trim($coupon)))->first();
            if (! $coupon || ! $coupon->isRedeemable()) {
                throw new DomainException(__('salon.errors.coupon_invalid'));
            }
            if ($isFirstCycle || $coupon->applies_to === 'forever') {
                $base = $subtotal - $cycleDiscount;
                $couponDiscount = $coupon->type === 'percent'
                    ? (int) round($base * $coupon->value / 10000)
                    : min($base, (int) $coupon->value);
                $couponCode = $coupon->code;
            }
        }

        $net = max(0, $subtotal - $cycleDiscount - $couponDiscount);
        $tax = (int) round($net * $taxPercent / 100);

        $allModules = array_values(array_unique(array_merge($plan->included_modules ?? [], $extraModules)));

        return new Quote($currency, $cycleMonths, $branches, $allModules, $lines, $subtotal, $cycleDiscount,
            $discountPercent, $couponDiscount, $taxPercent, $tax, $net + $tax, $couponCode);
    }

    protected function line(string $type, string $description, int $quantity, int $unit, array $meta = []): array
    {
        return ['type' => $type, 'description' => $description, 'quantity' => $quantity, 'unit_amount' => $unit, 'amount' => $quantity * $unit, 'meta' => $meta];
    }
}
