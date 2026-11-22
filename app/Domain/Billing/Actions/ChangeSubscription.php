<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use Laravel\Pennant\Feature;

/**
 * Mid-cycle upgrades/downgrades and add-ons (extra branches / modules) with proration.
 *
 * Proration = (new cycle price - old cycle price) x remaining fraction of the cycle.
 * Positive -> prorated invoice; change is applied when paid.
 * Negative (downgrade) -> applied immediately; the credit is recorded on the next renewal invoice.
 */
class ChangeSubscription
{
    public function __construct(protected CalculatePrice $calculator, protected IssueInvoice $invoices) {}

    public function preview(Subscription $subscription, Plan $plan, int $branches, array $extraModules): array
    {
        $old = (int) ($subscription->price_snapshot['total'] ?? 0);
        $quote = $this->calculator->handle($plan, $subscription->cycle_months, $branches, $extraModules, null, (float) ($subscription->price_snapshot['tax_percent'] ?? 0), isFirstCycle: false);
        $fraction = $subscription->remainingFraction();

        return [
            'quote' => $quote,
            'fraction' => $fraction,
            'prorated' => (int) round(($quote->total - $old) * $fraction),
        ];
    }

    public function handle(Subscription $subscription, Plan $plan, int $branches, array $extraModules): ?Invoice
    {
        ['quote' => $quote, 'prorated' => $prorated] = $this->preview($subscription, $plan, $branches, $extraModules);
        $change = ['plan_id' => $plan->id, 'branches_count' => $quote->branches, 'modules' => $quote->modules, 'price_snapshot' => $quote->toArray()];

        if ($prorated <= 0) {
            $credit = abs($prorated);
            $snapshot = $change['price_snapshot'] + ['pending_credit' => (int) ($subscription->price_snapshot['pending_credit'] ?? 0) + $credit];
            $subscription->update(array_merge($change, ['price_snapshot' => $snapshot]));
            Feature::flushCache();
            activity()->performedOn($subscription)->withProperties(['credit' => $credit])->log('subscription.downgraded');

            return null;
        }

        $line = [
            'type' => 'proration',
            'description' => __('salon.billing.line_proration', ['days' => $subscription->daysRemaining()]),
            'quantity' => 1, 'unit_amount' => $prorated, 'amount' => $prorated,
            'meta' => ['change' => $change],
        ];

        return $this->invoices->create($subscription->tenant, [$line], $quote->currency, 0, $subscription, 'proration', 3);
    }

    public function applyPaidChange(Invoice $invoice): void
    {
        $change = $invoice->items->firstWhere('type', 'proration')?->meta['change'] ?? null;
        if ($change) {
            $invoice->subscription->update($change);
            Feature::flushCache();
        }
    }
}
