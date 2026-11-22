<?php

namespace App\Domain\Booking\Availability;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per (tenant, branch) version key: cached days are keyed by the current version, so bumping it
 * invalidates every cached day of that branch at once (file/database stores have no tags).
 */
class AvailabilityCache
{
    public const TTL_SECONDS = 300;

    public function version(int $tenantId, int $branchId): string
    {
        return (string) Cache::get($this->versionKey($tenantId, $branchId), '0');
    }

    public function key(int $tenantId, int $branchId, string $date, string $hash): string
    {
        return "booking:av:{$tenantId}:{$branchId}:".$this->version($tenantId, $branchId).":{$date}:{$hash}";
    }

    public function remember(int $tenantId, int $branchId, string $date, string $hash, \Closure $compute): array
    {
        return Cache::remember($this->key($tenantId, $branchId, $date, $hash), self::TTL_SECONDS, $compute);
    }

    public function bump(?int $tenantId, int|array|null $branchIds): void
    {
        if (! $tenantId) {
            return;
        }
        foreach (array_unique(array_filter((array) $branchIds)) as $branchId) {
            Cache::forever($this->versionKey($tenantId, (int) $branchId), Str::random(10));
        }
    }

    /** Staff can work at several branches: bump all of them. */
    public function bumpForStaff(?int $tenantId, int|array|null $staffIds): void
    {
        $ids = array_filter((array) $staffIds);
        if (! $tenantId || ! $ids) {
            return;
        }
        $this->bump($tenantId, DB::table('branch_staff')->whereIn('staff_id', $ids)->pluck('branch_id')->all());
    }

    public function bumpTenant(?int $tenantId): void
    {
        if ($tenantId) {
            $this->bump($tenantId, DB::table('branches')->where('tenant_id', $tenantId)->pluck('id')->all());
        }
    }

    protected function versionKey(int $tenantId, int $branchId): string
    {
        return "booking:av-version:{$tenantId}:{$branchId}";
    }
}
