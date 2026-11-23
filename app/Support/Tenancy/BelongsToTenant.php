<?php

namespace App\Support\Tenancy;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Add to every tenant-owned model. Applies TenantScope and fills tenant_id on create.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = app(TenantContext::class)->id()
                    ?? throw new \RuntimeException(static::class.' requires an active tenant to be created.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
