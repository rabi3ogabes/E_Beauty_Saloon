<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Team\Models\Staff;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class Service extends Model
{
    use BelongsToTenant, HasTranslations, SoftDeletes;

    protected $guarded = ['id'];

    public array $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return [
            'resource_types' => 'array',
            'online_bookable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ServiceVariant::class)->orderBy('sort');
    }

    public function addons(): HasMany
    {
        return $this->hasMany(ServiceAddon::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withPivot(['is_active', 'price', 'duration_minutes']);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'staff_services')->withPivot(['price', 'duration_minutes']);
    }

    /** Effective price at a branch (branch override > sale price > base price), optional staff override. */
    public function priceAt(?Branch $branch = null, ?Staff $staff = null, ?ServiceVariant $variant = null): int
    {
        if ($variant) {
            return (int) $variant->price;
        }
        if ($staff && ($sp = $staff->services->firstWhere('id', $this->id)?->pivot?->price)) {
            return (int) $sp;
        }
        if ($branch && ($bp = $this->branchPivot($branch)?->price)) {
            return (int) $bp;
        }

        return (int) ($this->sale_price ?? $this->price);
    }

    public function durationAt(?Branch $branch = null, ?Staff $staff = null, ?ServiceVariant $variant = null): int
    {
        if ($variant) {
            return (int) $variant->duration_minutes;
        }
        if ($staff && ($sd = $staff->services->firstWhere('id', $this->id)?->pivot?->duration_minutes)) {
            return (int) $sd;
        }
        if ($branch && ($bd = $this->branchPivot($branch)?->duration_minutes)) {
            return (int) $bd;
        }

        return (int) $this->duration_minutes;
    }

    public function isActiveAt(Branch $branch): bool
    {
        $pivot = $this->branchPivot($branch);

        return $this->is_active && ($pivot === null || (bool) $pivot->is_active);
    }

    /** Deposit required in minor units for a given price. */
    public function depositFor(int $price): int
    {
        return match ($this->deposit_type) {
            'percent' => (int) round($price * $this->deposit_value / 10000), // basis points
            'fixed' => min($price, (int) $this->deposit_value),
            default => 0,
        };
    }

    protected function branchPivot(Branch $branch): ?object
    {
        $match = $this->relationLoaded('branches')
            ? $this->branches->firstWhere('id', $branch->id)
            : $this->branches()->withoutGlobalScopes()->where('branches.id', $branch->id)->first();

        return $match?->pivot;
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
