<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Subscription;

/** Generates the renewal invoice for the next cycle (once per cycle). */
class RenewSubscription
{
    public function __construct(protected CalculatePrice $calculator, protected IssueInvoice $invoices) {}

    public function handle(Subscription $subscription): Invoice
    {
        $existing = $subscription->invoices()->where('type', 'renewal')->where('status', 'open')
            ->where('created_at', '>=', $subscription->ends_at->copy()->subDays(14))->first();
        if ($existing) {
            return $existing;
        }

        $quote = $this->calculator->handle(
            $subscription->plan, $subscription->cycle_months, $subscription->branches_count,
            array_values(array_diff($subscription->modules, $subscription->plan->included_modules ?? [])),
            $subscription->coupon?->applies_to === 'forever' ? $subscription->coupon : null,
            (float) config('salon.defaults.tax_percent'),
            isFirstCycle: false,
        );

        $invoice = $this->invoices->fromQuote($subscription->tenant, $quote, $subscription, 'renewal',
            max(1, (int) now()->diffInDays($subscription->ends_at, false)));

        // Apply credit left over from downgrades.
        $credit = (int) ($subscription->price_snapshot['pending_credit'] ?? 0);
        if ($credit > 0 && $invoice->total > 0) {
            $applied = min($credit, $invoice->total);
            $invoice->items()->create(['type' => 'credit', 'description' => __('salon.billing.line_credit'), 'quantity' => 1, 'unit_amount' => -$applied, 'amount' => -$applied]);
            $invoice->update(['discount_total' => $invoice->discount_total + $applied, 'total' => $invoice->total - $applied]);
            $subscription->update(['price_snapshot' => array_merge($subscription->price_snapshot, ['pending_credit' => $credit - $applied])]);
        }

        return $invoice;
    }
}
