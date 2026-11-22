<?php

namespace App\Domain\POS\Exports;

use App\Support\Money\Money;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Payroll sheet: one row per staff member, amounts as decimals in the salon currency. */
class CommissionPayrollExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(protected array $rows, protected string $period, protected string $currency) {}

    public function array(): array
    {
        $dec = fn (int $minor) => (float) Money::of($minor, $this->currency)->toDecimal();

        $out = array_map(fn ($r) => [$r['staff'], $r['lines'], $dec($r['services']), $dec($r['products']), $dec($r['revenue']), $dec($r['commission']), $dec($r['tips']), $dec($r['payout'])], $this->rows);
        $out[] = [__('pos.ticket.total'), array_sum(array_column($this->rows, 'lines')), $dec(array_sum(array_column($this->rows, 'services'))), $dec(array_sum(array_column($this->rows, 'products'))),
            $dec(array_sum(array_column($this->rows, 'revenue'))), $dec(array_sum(array_column($this->rows, 'commission'))), $dec(array_sum(array_column($this->rows, 'tips'))), $dec(array_sum(array_column($this->rows, 'payout')))];

        return $out;
    }

    public function headings(): array
    {
        return [
            __('pos.commissions.staff'), __('pos.commissions.lines'), __('pos.commissions.services').' ('.$this->currency.')', __('pos.commissions.products').' ('.$this->currency.')',
            __('pos.commissions.revenue').' ('.$this->currency.')', __('pos.commissions.commission').' ('.$this->currency.')', __('pos.commissions.tips').' ('.$this->currency.')',
            __('pos.commissions.payout').' ('.$this->currency.')',
        ];
    }

    public function title(): string
    {
        return mb_substr(__('pos.commissions.title').' '.$this->period, 0, 31);
    }
}
