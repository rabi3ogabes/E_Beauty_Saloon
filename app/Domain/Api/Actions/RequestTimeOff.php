<?php

namespace App\Domain\Api\Actions;

use App\Domain\Team\Models\Staff;
use App\Domain\Team\Models\TimeOff;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;

/**
 * A stylist asks for time off from the staff app. Creates a *pending* request that the owner / manager reviews
 * with the Team module's ReviewTimeOff (dashboard); only approved time off blocks availability.
 * Whole days are interpreted in the salon timezone.
 */
class RequestTimeOff
{
    public function handle(Staff $staff, string $from, string $to, ?string $reason = null, string $type = 'leave'): TimeOff
    {
        abort_if(tenant()?->isReadOnly(), 423);
        $tz = tenant()->timezone ?? config('salon.defaults.timezone');
        $start = CarbonImmutable::parse($from, $tz)->startOfDay();
        $end = CarbonImmutable::parse($to, $tz)->endOfDay();

        $overlaps = TimeOff::query()->where('staff_id', $staff->id)->whereIn('status', ['pending', 'approved'])
            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())->exists();
        if ($overlaps) {
            throw new DomainException(__('api.staff.time_off_overlaps'));
        }

        $request = TimeOff::create([
            'staff_id' => $staff->id,
            'starts_at' => $start->utc(),
            'ends_at' => $end->utc(),
            'type' => $type,
            'reason' => $reason ? mb_substr($reason, 0, 250) : null,
            'status' => 'pending',
        ]);
        activity()->performedOn($request)->withProperties(['source' => 'staff_app'])->log('time_off.requested');

        return $request;
    }
}
