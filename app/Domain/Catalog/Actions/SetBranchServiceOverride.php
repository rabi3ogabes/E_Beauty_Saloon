<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;

/** Per-branch override of a service (branch_service pivot): active flag, price and duration. Null = inherit. */
class SetBranchServiceOverride
{
    public function handle(Service $service, Branch $branch, bool $active = true, ?int $price = null, ?int $duration = null): Service
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        abort_unless($service->tenant_id === $branch->tenant_id, 404);

        $service->branches()->syncWithoutDetaching([$branch->id => [
            'is_active' => $active,
            'price' => $price,
            'duration_minutes' => $duration ?: null,
        ]]);
        $service->unsetRelation('branches');

        activity()->performedOn($service)->causedBy(auth()->user())
            ->withProperties(['branch_id' => $branch->id, 'active' => $active, 'price' => $price, 'duration' => $duration])->log('service.branch_override');

        return $service;
    }
}
