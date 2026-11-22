<?php

namespace App\Domain\Team\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class Staff extends Model
{
    use BelongsToTenant, HasTranslations, SoftDeletes;

    protected $table = 'staff';

    protected $guarded = ['id'];

    public array $translatable = ['name', 'title', 'bio'];

    protected static function booted(): void
    {
        // Respect branch switcher + branch restrictions through the branch_staff pivot.
        static::addGlobalScope('branch_visibility', function (Builder $q) {
            $ctx = app(TenantContext::class);
            $ids = $ctx->branchId() ? [$ctx->branchId()] : $ctx->allowedBranchIds();
            if ($ids !== null) {
                $q->whereHas('branches', fn ($b) => $b->withoutGlobalScopes()->whereIn('branches.id', $ids ?: [0]));
            }
        });
    }

    protected function casts(): array
    {
        return ['is_bookable' => 'boolean', 'is_active' => 'boolean', 'rating' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_staff');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'staff_services')->withPivot(['price', 'duration_minutes']);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(StaffSchedule::class);
    }

    public function timeOff(): HasMany
    {
        return $this->hasMany(TimeOff::class);
    }

    public function commissionRules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }

    public function canPerform(Service $service): bool
    {
        return $this->services->contains('id', $service->id);
    }

    public function scopeBookable($q)
    {
        return $q->where('is_active', true)->where('is_bookable', true);
    }
}
