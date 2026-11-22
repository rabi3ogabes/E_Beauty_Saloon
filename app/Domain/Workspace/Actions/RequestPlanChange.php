<?php

namespace App\Domain\Workspace\Actions;

use App\Domain\Billing\Actions\ChangeSubscription;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Tenancy\Models\Tenant;

/**
 * Owner-initiated change (upgrade plan, add branches or modules). Upgrades create a prorated
 * invoice that applies the change once paid; downgrades apply immediately with a credit.
 */
class RequestPlanChange
{
    public function __construct(protected PreviewPlanChange $preview, protected ChangeSubscription $changes) {}

    public function handle(Tenant $tenant, ?int $planId = null, ?int $branches = null, ?array $extraModules = null): ?Invoice
    {
        $p = $this->preview->handle($tenant, $planId, $branches, $extraModules);

        $invoice = $this->changes->handle($p['subscription'], $p['plan'], $p['branches'], $p['modules']);

        activity()->performedOn($tenant)->causedBy(auth()->user())
            ->withProperties(['plan_id' => $p['plan']->id, 'branches' => $p['branches'], 'modules' => $p['modules'], 'invoice' => $invoice?->number])
            ->log('subscription.change_requested');

        return $invoice;
    }
}
