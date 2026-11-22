<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\CRM\Models\Client;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Marketing quiet hours (tenant setting marketing.quiet_hours = {"enabled":true,"start":"21:00","end":"09:00"}),
 * evaluated in the client's preferred branch timezone (else the tenant timezone). Messages due inside the window are delayed.
 */
class QuietHours
{
    public function window(): array
    {
        $cfg = tenant()?->setting('marketing.quiet_hours') ?? [];

        return [
            'enabled' => (bool) ($cfg['enabled'] ?? true),
            'start' => $cfg['start'] ?? '21:00',
            'end' => $cfg['end'] ?? '09:00',
        ];
    }

    public function timezoneFor(?Client $client): string
    {
        $branchId = $client?->preferred_branch_id;
        $tz = $branchId ? Branch::withoutGlobalScopes()->whereKey($branchId)->value('timezone') : null;

        return $tz ?: (tenant()?->timezone ?? config('salon.defaults.timezone'));
    }

    public function isQuiet(?Client $client, ?CarbonInterface $at = null): bool
    {
        return $this->nextAllowed($client, $at) !== null;
    }

    /** Null when sending is allowed now; otherwise the UTC moment the window ends. */
    public function nextAllowed(?Client $client, ?CarbonInterface $at = null): ?CarbonImmutable
    {
        $w = $this->window();
        if (! $w['enabled'] || $w['start'] === $w['end']) {
            return null;
        }
        $tz = $this->timezoneFor($client);
        $local = CarbonImmutable::instance($at ?? now())->setTimezone($tz);
        $start = $local->setTimeFromTimeString($w['start']);
        $end = $local->setTimeFromTimeString($w['end']);

        if ($start->lt($end)) { // same-day window, e.g. 13:00–15:00
            return $local->gte($start) && $local->lt($end) ? $end->utc() : null;
        }
        // Overnight window, e.g. 21:00–09:00
        if ($local->gte($start)) {
            return $end->addDay()->utc();
        }
        if ($local->lt($end)) {
            return $end->utc();
        }

        return null;
    }
}
