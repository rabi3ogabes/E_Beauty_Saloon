<?php

namespace App\Support\Tenancy;

use App\Domain\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function branchColumn(): string
    {
        return 'branch_id';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, $this->branchColumn());
    }
}
