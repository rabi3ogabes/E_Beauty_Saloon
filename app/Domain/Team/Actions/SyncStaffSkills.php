<?php

namespace App\Domain\Team\Actions;

use App\Domain\Catalog\Models\Service;
use App\Domain\Team\Models\Staff;

/**
 * Skills = services a staff member performs, each with an optional own price (minor units)
 * and duration (staff_services pivot).
 */
class SyncStaffSkills
{
    /** @param  list<array{service_id:int, price?:?int, duration_minutes?:?int}>  $rows */
    public function handle(Staff $staff, array $rows): Staff
    {
        $valid = Service::query()->whereIn('id', collect($rows)->pluck('service_id')->filter())->pluck('id')->all();

        $sync = collect($rows)
            ->filter(fn ($r) => in_array((int) ($r['service_id'] ?? 0), $valid, true))
            ->mapWithKeys(fn ($r) => [(int) $r['service_id'] => [
                'price' => filled($r['price'] ?? null) ? (int) $r['price'] : null,
                'duration_minutes' => filled($r['duration_minutes'] ?? null) ? (int) $r['duration_minutes'] : null,
            ]])->all();

        $staff->services()->sync($sync);
        $staff->unsetRelation('services');

        return $staff;
    }
}
