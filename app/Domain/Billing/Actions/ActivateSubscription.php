<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenancy\Enums\TenantStatus;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/** Activates (or re-activates after suspension) a subscription and its tenant. */
class ActivateSubscription
{
    public function handle(Subscription $subscription, bool $extendCycle = false): Subscription
    {
        return DB::transaction(function () use ($subscription, $extendCycle) {
            $tenant = $subscription->tenant;

            // Retire any other live subscription for this tenant.
            $tenant->subscriptions()->whereKeyNot($subscription->id)
                ->whereIn('status', array_map(fn ($s) => $s->value, SubscriptionStatus::entitled()))
                ->update(['status' => SubscriptionStatus::Expired->value]);

            $updates = ['status' => SubscriptionStatus::Active];
            if ($subscription->status === SubscriptionStatus::Trialing || $subscription->status === SubscriptionStatus::Pending) {
                // Paid cycle starts when the trial ends (or now when there is no trial left).
                $start = $subscription->status === SubscriptionStatus::Trialing && $subscription->ends_at?->isFuture() ? $subscription->ends_at : now();
                $updates['starts_at'] = $subscription->status === SubscriptionStatus::Pending ? now() : $subscription->starts_at;
                $updates['ends_at'] = $start->copy()->addMonthsNoOverflow($subscription->cycle_months);
            } elseif ($extendCycle) {
                $from = $subscription->ends_at && $subscription->ends_at->isFuture() ? $subscription->ends_at : now();
                $updates['ends_at'] = $from->copy()->addMonthsNoOverflow($subscription->cycle_months);
            }
            $subscription->update($updates);

            $tenant->update(['status' => TenantStatus::Active, 'grace_ends_at' => null]);
            Feature::flushCache();

            return $subscription;
        });
    }
}
