<?php

namespace App\Domain\POS\Support;

/**
 * Pure ticket maths shared by the terminal (live totals) and CompleteSale (server truth).
 *
 * Line input:  ['type' => service|product|gift_card|package, 'qty' => 1, 'unit_price' => 15000,
 *               'discount_type' => percent|fixed|null, 'discount_value' => 1000 (basis points or minor units),
 *               'tax_percent' => ?float (null = branch rate)]
 * Ticket discount: ['type' => percent|fixed, 'value' => int]  — allocated pro-rata over discountable lines.
 * Gift card lines are never discounted nor taxed (they are stored value).
 */
class TicketCalculator
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int|string, int>  $tips  staff_id => minor units
     * @return array{lines: array<int, array<string, int|float>>, subtotal: int, discount: int, tax: int, tips: int, total: int}
     */
    public static function calculate(array $lines, ?array $ticketDiscount, array $tips, float $branchTaxPercent, bool $taxInclusive): array
    {
        $out = [];
        foreach (array_values($lines) as $i => $line) {
            $qty = max(1, (int) ($line['qty'] ?? 1));
            $gross = (int) $line['unit_price'] * $qty;
            $discountable = ($line['type'] ?? '') !== 'gift_card';
            $lineDiscount = $discountable ? self::discountOf($gross, $line['discount_type'] ?? null, (int) ($line['discount_value'] ?? 0)) : 0;
            $out[$i] = ['gross' => $gross, 'discount' => $lineDiscount, 'discountable' => $discountable, 'tax_percent' => $discountable ? (float) ($line['tax_percent'] ?? $branchTaxPercent) : 0.0];
        }

        // Ticket-level discount, allocated pro-rata (remainder on the last discountable line).
        $base = array_sum(array_map(fn ($l) => $l['discountable'] ? $l['gross'] - $l['discount'] : 0, $out));
        $ticket = $ticketDiscount ? min($base, self::discountOf($base, $ticketDiscount['type'] ?? null, (int) ($ticketDiscount['value'] ?? 0))) : 0;
        if ($ticket > 0 && $base > 0) {
            $allocated = 0;
            $keys = array_keys(array_filter($out, fn ($l) => $l['discountable'] && $l['gross'] - $l['discount'] > 0));
            foreach ($keys as $n => $k) {
                $share = $n === array_key_last($keys) ? $ticket - $allocated : intdiv(($out[$k]['gross'] - $out[$k]['discount']) * $ticket, $base);
                $out[$k]['discount'] += $share;
                $allocated += $share;
            }
        }

        $totals = ['subtotal' => 0, 'discount' => 0, 'tax' => 0, 'tips' => 0, 'total' => 0];
        foreach ($out as $i => $l) {
            $net = max(0, $l['gross'] - $l['discount']);
            $bp = (int) round($l['tax_percent'] * 100);
            if ($bp <= 0) {
                $tax = 0;
                $total = $net;
            } elseif ($taxInclusive) {
                $tax = $net - (int) round($net * 10000 / (10000 + $bp));
                $total = $net;
            } else {
                $tax = (int) round($net * $bp / 10000);
                $total = $net + $tax;
            }
            $out[$i] = ['gross' => $l['gross'], 'discount' => min($l['gross'], $l['discount']), 'tax' => $tax, 'total' => $total, 'net' => $total - $tax, 'tax_percent' => $l['tax_percent']];
            $totals['subtotal'] += $l['gross'];
            $totals['discount'] += $out[$i]['discount'];
            $totals['tax'] += $tax;
            $totals['total'] += $total;
        }

        $totals['tips'] = array_sum(array_map('intval', $tips));
        $totals['total'] += $totals['tips'];

        return ['lines' => $out] + $totals;
    }

    public static function discountOf(int $amount, ?string $type, int $value): int
    {
        return match ($type) {
            'percent' => min($amount, (int) round($amount * min(10000, max(0, $value)) / 10000)),
            'fixed' => min($amount, max(0, $value)),
            default => 0,
        };
    }
}
