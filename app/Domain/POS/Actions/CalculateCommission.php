<?php

namespace App\Domain\POS\Actions;

use App\Domain\POS\Models\Commission;
use App\Domain\POS\Models\Sale;
use App\Domain\POS\Models\SaleItem;
use App\Domain\Team\Models\CommissionRule;
use Illuminate\Support\Collection;

/**
 * Commission for one sale line from CommissionRule:
 *  - staff-specific rule wins over the default (staff_id null),
 *  - then a target-specific rule (e.g. this service) over a generic one,
 *  - then applies_to = line type over "all".
 * Percent values are basis points of the line net (excl. tax); fixed values are minor units per unit sold.
 * Tiers ([{"from": minor, "value": bp}]) pick the value by the staff member's revenue in the period,
 * including this line.
 */
class CalculateCommission
{
    public function handle(Sale $sale, SaleItem $item, ?Collection $rules = null): ?Commission
    {
        if (! $item->staff_id || $item->type === 'gift_card') {
            return null;
        }

        $base = (int) $item->total - (int) $item->tax;
        $rule = $this->ruleFor($item, $rules ?? CommissionRule::withoutGlobalScopes()->where('tenant_id', $sale->tenant_id)->get());
        if (! $rule || $base <= 0) {
            return null;
        }

        $period = ($sale->completed_at ?? now())->format('Y-m');
        $value = (int) $rule->value;
        if (! empty($rule->tiers)) {
            $periodRevenue = (int) Commission::withoutGlobalScopes()->where('tenant_id', $sale->tenant_id)
                ->where('staff_id', $item->staff_id)->where('period', $period)->sum('base_amount') + $base;
            foreach (collect($rule->tiers)->sortBy('from') as $tier) {
                if ($periodRevenue >= (int) $tier['from']) {
                    $value = (int) $tier['value'];
                }
            }
        }

        $amount = $rule->type === 'fixed' ? $value * (int) $item->qty : (int) round($base * $value / 10000);

        $commission = new Commission;
        $commission->timestamps = false; // accrues when the sale happened (offline / back-dated sales)

        $commission->forceFill([
            'tenant_id' => $sale->tenant_id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'sale_item_id' => $item->id,
            'staff_id' => $item->staff_id,
            'commission_rule_id' => $rule->id,
            'base_amount' => $base,
            'amount' => $amount,
            'period' => $period,
            'rule_snapshot' => ['type' => $rule->type, 'value' => $value, 'applies_to' => $rule->applies_to, 'staff_id' => $rule->staff_id, 'target' => $rule->target_type ? class_basename($rule->target_type).'#'.$rule->target_id : null],
            'created_at' => $sale->completed_at ?? now(),
            'updated_at' => now(),
        ])->save();

        return $commission;
    }

    public function ruleFor(SaleItem $item, Collection $rules): ?CommissionRule
    {
        $targetType = $item->sellable_type;
        $targetId = $item->sellable_id;

        return $rules
            ->filter(fn (CommissionRule $r) => ($r->staff_id === null || (int) $r->staff_id === (int) $item->staff_id)
                && in_array($r->applies_to, [$item->type, 'all'], true)
                && ($r->target_type === null || ($r->target_type === $targetType && (int) $r->target_id === (int) $targetId)))
            ->sortByDesc(fn (CommissionRule $r) => ($r->staff_id ? 4 : 0) + ($r->target_type ? 2 : 0) + ($r->applies_to === $item->type ? 1 : 0))
            ->first();
    }
}
