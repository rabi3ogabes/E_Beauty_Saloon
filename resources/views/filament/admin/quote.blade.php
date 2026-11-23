{{-- Live price breakdown from CalculatePrice --}}
<div class="space-y-3">
    <table class="w-full text-sm">
        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($quote->lines as $line)
                <tr>
                    <td class="py-2 text-gray-700 dark:text-gray-300">{{ $line['description'] }}</td>
                    <td class="py-2 text-end tabular-nums">{{ money($line['amount'], $quote->currency) }}</td>
                </tr>
            @endforeach
            <tr><td class="py-2 font-medium">{{ __('salon.billing.subtotal') }}</td><td class="py-2 text-end font-medium tabular-nums">{{ money($quote->subtotal, $quote->currency) }}</td></tr>
            @if ($quote->cycleDiscount)
                <tr class="text-success-700 dark:text-success-400"><td class="py-2">{{ __('salon.billing.line_cycle_discount', ['percent' => rtrim(rtrim(number_format($quote->cycleDiscountPercent, 2), '0'), '.')]) }}</td><td class="py-2 text-end tabular-nums">− {{ money($quote->cycleDiscount, $quote->currency) }}</td></tr>
            @endif
            @if ($quote->couponDiscount)
                <tr class="text-success-700 dark:text-success-400"><td class="py-2">{{ __('salon.billing.line_coupon', ['code' => $quote->couponCode]) }}</td><td class="py-2 text-end tabular-nums">− {{ money($quote->couponDiscount, $quote->currency) }}</td></tr>
            @endif
            @if ($quote->tax)
                <tr><td class="py-2">{{ __('salon.billing.tax') }} ({{ $quote->taxPercent }}%)</td><td class="py-2 text-end tabular-nums">{{ money($quote->tax, $quote->currency) }}</td></tr>
            @endif
        </tbody>
    </table>
    <div class="flex items-end justify-between rounded-2xl bg-primary-50 px-4 py-3 dark:bg-primary-500/10">
        <div>
            <p class="text-xs uppercase tracking-wider text-gray-500">{{ __('salon.billing.total') }}</p>
            <p class="text-xs text-gray-500">{{ __('salon.billing.per_month_equiv', ['amount' => money($quote->monthlyEquivalent(), $quote->currency)]) }}</p>
        </div>
        <p class="luxe-display text-2xl tabular-nums text-gray-950 dark:text-white">{{ money($quote->total, $quote->currency) }}</p>
    </div>
</div>
