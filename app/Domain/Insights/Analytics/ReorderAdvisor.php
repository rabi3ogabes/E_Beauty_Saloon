<?php

namespace App\Domain\Insights\Analytics;

use App\Domain\Inventory\Models\BranchStock;
use App\Domain\Inventory\Models\StockMovement;
use Carbon\CarbonImmutable;

/**
 * Stock reorder suggestions from usage trends: daily use = units out (sales, service use, shop orders) over the
 * last 28 days ÷ 28. Suggest when fewer than LEAD_DAYS of cover remain or the item is at its threshold;
 * quantity covers 30 days plus the safety threshold.
 */
class ReorderAdvisor
{
    public const LEAD_DAYS = 14;

    public const COVER_DAYS = 30;

    public const USAGE_TYPES = ['sale', 'used_in_service', 'order'];

    /** @return list<array{branch_id:int, branch:string, variant_id:int, item:string, on_hand:int, threshold:int, daily_usage:float, days_cover:?float, suggested_qty:int, unit_cost:int}> */
    public function suggestions(?int $branchId = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stock = BranchStock::query()->with(['variant.product', 'branch'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->get();
        $usage = StockMovement::query()->whereIn('type', self::USAGE_TYPES)->where('qty', '<', 0)
            ->where('created_at', '>=', $now->subDays(28))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['branch_id', 'product_variant_id', 'qty'])
            ->groupBy(fn ($m) => $m->branch_id.'-'.$m->product_variant_id)
            ->map(fn ($g) => (int) -$g->sum('qty'));

        $out = [];
        foreach ($stock as $row) {
            if (! $row->variant || ! ($row->variant->product?->track_stock ?? true)) {
                continue;
            }
            $available = $row->qty - $row->reserved;
            $daily = round(($usage[$row->branch_id.'-'.$row->product_variant_id] ?? 0) / 28, 2);
            $cover = $daily > 0 ? round(max(0, $available) / $daily, 1) : null;
            $low = $row->qty <= $row->low_stock_threshold;
            if (! $low && ($cover === null || $cover >= self::LEAD_DAYS)) {
                continue;
            }
            $qty = $daily > 0
                ? (int) max(1, ceil($daily * self::COVER_DAYS + $row->low_stock_threshold - $available))
                : (int) max(1, $row->low_stock_threshold * 2 - $available);
            $out[] = [
                'branch_id' => (int) $row->branch_id,
                'branch' => (string) $row->branch?->name,
                'variant_id' => (int) $row->product_variant_id,
                'item' => $row->variant->displayName(),
                'on_hand' => (int) $row->qty,
                'threshold' => (int) $row->low_stock_threshold,
                'daily_usage' => $daily,
                'days_cover' => $cover,
                'suggested_qty' => $qty,
                'unit_cost' => (int) $row->variant->cost,
            ];
        }

        return collect($out)->sortBy(fn ($r) => $r['days_cover'] ?? -1)->values()->all();
    }
}
