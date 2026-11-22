<?php

namespace App\Domain\POS\Actions;

use App\Domain\POS\Enums\SaleStatus;
use App\Domain\POS\Models\CashMovement;
use App\Domain\POS\Models\Sale;
use App\Domain\POS\Models\SalePayment;
use App\Domain\POS\Models\SaleRefund;
use App\Domain\POS\Models\Shift;

/**
 * Drawer maths for a shift (X-report while open, Z-report at close):
 *   expected cash = opening float + cash sales − cash refunds − cash outs + cash ins
 */
class BuildShiftSummary
{
    /** @return array<string, mixed> */
    public function handle(Shift $shift): array
    {
        $saleIds = Sale::withoutGlobalScopes()->where('pos_shift_id', $shift->id)->whereIn('status', SaleStatus::settled())->pluck('id');
        $sales = Sale::withoutGlobalScopes()->whereIn('id', $saleIds);

        $byMethod = SalePayment::whereIn('sale_id', $saleIds)->selectRaw('method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('method')->get()->mapWithKeys(fn ($r) => [$r->method => ['total' => (int) $r->total, 'count' => (int) $r->count]])->all();

        $refunds = SaleRefund::withoutGlobalScopes()->where('pos_shift_id', $shift->id);
        $movements = CashMovement::withoutGlobalScopes()->where('pos_shift_id', $shift->id);

        $cashSales = (int) ($byMethod['cash']['total'] ?? 0);
        $cashRefunds = (int) (clone $refunds)->sum('cash_amount');
        $cashIn = (int) (clone $movements)->where('type', 'in')->sum('amount');
        $cashOut = (int) (clone $movements)->where('type', 'out')->sum('amount');

        return [
            'opening_float' => (int) $shift->opening_float,
            'cash_sales' => $cashSales,
            'cash_refunds' => $cashRefunds,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected_cash' => (int) $shift->opening_float + $cashSales - $cashRefunds - $cashOut + $cashIn,
            'sales_count' => $saleIds->count(),
            'gross' => (int) (clone $sales)->sum('subtotal'),
            'discounts' => (int) (clone $sales)->sum('discount'),
            'tax' => (int) (clone $sales)->sum('tax'),
            'tips' => (int) (clone $sales)->sum('tips'),
            'net_sales' => (int) (clone $sales)->sum('total'),
            'refunds_total' => (int) (clone $refunds)->sum('amount'),
            'refunds_count' => (clone $refunds)->count(),
            'voids_count' => (int) (clone $sales)->where('status', SaleStatus::Voided->value)->count(),
            'by_method' => $byMethod,
        ];
    }
}
