<?php

namespace App\Domain\POS\Actions;

use App\Domain\POS\Enums\SaleStatus;
use App\Domain\POS\Models\Commission;
use App\Domain\POS\Models\Sale;
use App\Domain\Team\Models\Staff;
use Carbon\CarbonInterface;

/**
 * Per-staff commission & tips totals for a period (payroll). Reversals from refunds are counted in the
 * period they happened, so a closed payroll period never changes.
 */
class BuildCommissionReport
{
    /** @return array<int, array{staff_id: int, staff: string, lines: int, services: int, products: int, revenue: int, commission: int, tips: int, payout: int}> */
    public function handle(CarbonInterface $from, CarbonInterface $until, ?int $branchId = null): array
    {
        $commissions = Commission::query()
            ->with('saleItem:id,type')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $until->copy()->endOfDay()])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get();

        $tips = [];
        Sale::query()->whereIn('status', SaleStatus::settled())->where('status', '!=', SaleStatus::Voided->value)->where('tips', '>', 0)
            ->whereBetween('completed_at', [$from->copy()->startOfDay(), $until->copy()->endOfDay()])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'tips_meta'])
            ->each(function (Sale $sale) use (&$tips) {
                foreach ($sale->tips_meta ?? [] as $tip) {
                    $tips[(int) $tip['staff_id']] = ($tips[(int) $tip['staff_id']] ?? 0) + (int) $tip['amount'];
                }
            });

        $staffIds = $commissions->pluck('staff_id')->merge(array_keys($tips))->unique()->filter();
        $names = Staff::withoutGlobalScopes()->withTrashed()->whereIn('id', $staffIds)->get()->mapWithKeys(fn (Staff $s) => [$s->id => (string) $s->name]);

        return $staffIds->map(function ($staffId) use ($commissions, $tips, $names) {
            $rows = $commissions->where('staff_id', $staffId);
            $commission = (int) $rows->sum('amount');
            $tip = (int) ($tips[$staffId] ?? 0);

            return [
                'staff_id' => (int) $staffId,
                'staff' => $names[$staffId] ?? '#'.$staffId,
                'lines' => $rows->whereNull('reversal_of_id')->count(),
                'services' => (int) $rows->filter(fn ($c) => $c->saleItem?->type === 'service')->sum('base_amount'),
                'products' => (int) $rows->filter(fn ($c) => $c->saleItem?->type === 'product')->sum('base_amount'),
                'revenue' => (int) $rows->sum('base_amount'),
                'commission' => $commission,
                'tips' => $tip,
                'payout' => $commission + $tip,
            ];
        })->sortByDesc('payout')->values()->all();
    }
}
