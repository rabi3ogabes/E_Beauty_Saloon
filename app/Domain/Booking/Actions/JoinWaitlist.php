<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Models\WaitlistEntry;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;

/** Join the waitlist for services on a date range (optionally a staff member and a time window). */
class JoinWaitlist
{
    public const WINDOWS = ['any', 'morning', 'afternoon', 'evening'];

    public function handle(Client $client, Branch $branch, array $serviceIds, string $from, ?string $to = null, ?int $staffId = null, string $window = 'any'): WaitlistEntry
    {
        abort_if(tenant()?->isReadOnly(), 423);
        $serviceIds = array_values(array_unique(array_map('intval', $serviceIds)));
        if (! $serviceIds || Service::whereIn('id', $serviceIds)->count() !== count($serviceIds)) {
            throw new DomainException(__('booking.errors.services_required'));
        }
        $fromDate = CarbonImmutable::parse($from);
        $toDate = CarbonImmutable::parse($to ?? $from);
        if ($toDate->lt($fromDate) || $fromDate->lt(now($branch->timezone)->startOfDay()) || $fromDate->diffInDays($toDate) > 30) {
            throw new DomainException(__('booking.errors.waitlist_dates'));
        }

        return WaitlistEntry::updateOrCreate(
            ['branch_id' => $branch->id, 'client_id' => $client->id, 'date_from' => $fromDate->toDateString(), 'status' => 'waiting'],
            ['service_ids' => $serviceIds, 'staff_id' => $staffId, 'date_to' => $toDate->toDateString(), 'time_window' => in_array($window, self::WINDOWS, true) ? $window : 'any'],
        );
    }
}
