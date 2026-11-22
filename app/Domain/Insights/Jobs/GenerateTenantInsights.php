<?php

namespace App\Domain\Insights\Jobs;

use App\Domain\Insights\Actions\GenerateInsights;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One tenant's nightly insights (stores only the tenant id; GenerateInsights runs inside TenantContext::run). */
class GenerateTenantInsights implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $uniqueFor = 3600;

    public function __construct(public int $tenantId) {}

    public function uniqueId(): string
    {
        return 'insights:'.$this->tenantId;
    }

    public function handle(GenerateInsights $generate): void
    {
        if ($tenant = Tenant::find($this->tenantId)) {
            $generate->handle($tenant);
        }
    }

    /** Fan out one job per active tenant (scheduled nightly). */
    public static function dispatchForAllTenants(): int
    {
        $count = 0;
        Tenant::query()->whereIn('status', ['trial', 'active', 'grace'])->pluck('id')->each(function ($id) use (&$count) {
            static::dispatch((int) $id);
            $count++;
        });

        return $count;
    }
}
