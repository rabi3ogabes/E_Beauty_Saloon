<?php

namespace App\Support\Tenancy;

use App\Domain\Tenancy\Models\Tenant;
use Closure;

/**
 * Holds the tenant (and optionally the branch) the current request / job runs for.
 *
 * Resolved once per request by ResolveTenant middleware, or explicitly with run()
 * inside queued jobs, console commands and tests.
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    /** Selected branch from the branch switcher; null = all branches. */
    protected ?int $branchId = null;

    /** Branch ids the current user may see; null = unrestricted. */
    protected ?array $allowedBranchIds = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->branchId = null;
        $this->allowedBranchIds = null;

        if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($tenant?->getKey() ?? 0);
        }
    }

    /** Swap in a freshly loaded instance of the SAME tenant, keeping branch scoping. */
    public function replaceTenantInstance(Tenant $tenant): void
    {
        if ($this->tenant?->getKey() === $tenant->getKey()) {
            $this->tenant = $tenant;
        }
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function tenantOrFail(): Tenant
    {
        return $this->tenant ?? throw new \RuntimeException('No tenant is active in this context.');
    }

    public function setBranch(?int $branchId): void
    {
        $this->branchId = $branchId;
    }

    public function branchId(): ?int
    {
        return $this->branchId;
    }

    public function restrictToBranches(?array $ids): void
    {
        $this->allowedBranchIds = $ids === null ? null : array_values(array_map('intval', $ids));
    }

    public function allowedBranchIds(): ?array
    {
        return $this->allowedBranchIds;
    }

    /**
     * Run a callback as a given tenant and restore the previous context afterwards.
     *
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(?Tenant $tenant, Closure $callback): mixed
    {
        $previous = [$this->tenant, $this->branchId, $this->allowedBranchIds];
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            [$t, $b, $a] = $previous;
            $this->set($t);
            $this->branchId = $b;
            $this->allowedBranchIds = $a;
        }
    }
}
