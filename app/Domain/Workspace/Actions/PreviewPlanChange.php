<?php

namespace App\Domain\Workspace\Actions;

use App\Domain\Billing\Actions\ChangeSubscription;
use App\Domain\Billing\DTOs\Quote;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Exceptions\DomainException;

/**
 * Prorated preview of a plan / branches / add-on modules change requested by the salon owner.
 * Any argument left null keeps the current value.
 *
 * @return array{subscription: Subscription, plan: Plan, branches: int, modules: list<string>, quote: Quote, fraction: float, prorated: int}
 */
class PreviewPlanChange
{
    public function __construct(protected ChangeSubscription $changes) {}

    public function handle(Tenant $tenant, ?int $planId = null, ?int $branches = null, ?array $extraModules = null): array
    {
        $subscription = $tenant->currentSubscription
            ?? throw new DomainException(__('workspace.billing.no_subscription'));

        $plan = $planId ? Plan::findOrFail($planId) : $subscription->plan;
        $branches = max(1, $branches ?? (int) $subscription->branches_count);
        $extraModules = array_values($extraModules ?? static::extraModules($subscription));
        $extraModules = array_values(array_diff($extraModules, $plan->included_modules ?? []));

        $preview = $this->changes->preview($subscription, $plan, $branches, $extraModules);

        return ['subscription' => $subscription, 'plan' => $plan, 'branches' => $branches, 'modules' => $extraModules] + $preview;
    }

    /** Add-on modules bought on top of the plan. */
    public static function extraModules(Subscription $subscription): array
    {
        return array_values(array_diff($subscription->modules ?? [], $subscription->plan?->included_modules ?? []));
    }
}
