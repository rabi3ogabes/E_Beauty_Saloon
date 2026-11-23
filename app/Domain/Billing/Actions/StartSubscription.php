<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Creates a subscription for a tenant and its first invoice.
 * With $trial the subscription is "trialing" immediately (no payment needed until trial end);
 * otherwise it is "pending" and becomes active when the invoice is paid (ActivateInvoice).
 */
class StartSubscription
{
    public function __construct(protected CalculatePrice $calculator, protected IssueInvoice $invoices) {}

    /** @return array{0: Subscription, 1: Invoice} */
    public function handle(Tenant $tenant, Plan $plan, int $cycleMonths, int $branches = 1, array $extraModules = [], ?string $couponCode = null, bool $trial = false, bool $autoRenew = true): array
    {
        return DB::transaction(function () use ($tenant, $plan, $cycleMonths, $branches, $extraModules, $couponCode, $trial, $autoRenew) {
            $coupon = $couponCode ? Coupon::where('code', strtoupper($couponCode))->lockForUpdate()->first() : null;
            $quote = $this->calculator->handle($plan, $cycleMonths, $branches, $extraModules, $coupon ?? $couponCode, (float) config('salon.defaults.tax_percent'));

            $trialDays = $trial ? $plan->trial_days : 0;
            $starts = now();
            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'coupon_id' => $coupon?->id,
                'cycle_months' => $cycleMonths,
                'branches_count' => $quote->branches,
                'modules' => $quote->modules,
                'status' => $trialDays > 0 ? SubscriptionStatus::Trialing : SubscriptionStatus::Pending,
                'starts_at' => $starts,
                'ends_at' => $trialDays > 0 ? $starts->copy()->addDays($trialDays) : $starts->copy()->addMonthsNoOverflow($cycleMonths),
                'auto_renew' => $autoRenew,
                'price_snapshot' => $quote->toArray(),
            ]);

            if ($coupon) {
                $coupon->increment('used_count');
            }

            if ($trialDays > 0) {
                $tenant->update(['status' => TenantStatus::Trial, 'trial_ends_at' => $subscription->ends_at]);
            }

            $invoice = $this->invoices->fromQuote($tenant, $quote, $subscription, 'subscription', $trialDays ?: 7);

            if ($invoice->total === 0 && ! $trialDays) {
                app(ActivateSubscription::class)->handle($subscription);
            }

            activity()->performedOn($subscription)->withProperties(['quote' => $quote->toArray()])->log('subscription.started');

            return [$subscription->fresh(), $invoice];
        });
    }
}
