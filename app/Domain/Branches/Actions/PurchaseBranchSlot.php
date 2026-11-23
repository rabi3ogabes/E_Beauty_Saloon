<?php

namespace App\Domain\Branches\Actions;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Workspace\Actions\RequestPlanChange;

/** "Add branch" when at the plan limit: issues the prorated invoice for N more branches. */
class PurchaseBranchSlot
{
    public function __construct(protected RequestPlanChange $change) {}

    public function handle(Tenant $tenant, int $extra = 1): ?Invoice
    {
        return $this->change->handle($tenant, branches: $tenant->branchLimit() + max(1, $extra));
    }
}
