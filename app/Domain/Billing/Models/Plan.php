<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class Plan extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    public array $translatable = ['name', 'tagline'];

    protected function casts(): array
    {
        return [
            'included_modules' => 'array',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function billingCycles(): HasMany
    {
        return $this->hasMany(BillingCycle::class)->orderBy('months');
    }

    public function modulePrices(): HasMany
    {
        return $this->hasMany(ModulePrice::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Cycle discount for this plan, falling back to platform defaults. */
    public function discountForCycle(int $months): float
    {
        $cycle = $this->billingCycles->firstWhere('months', $months)
            ?? BillingCycle::query()->whereNull('plan_id')->where('months', $months)->first();

        return (float) ($cycle?->discount_percent ?? 0);
    }

    public function includesModule(string $key): bool
    {
        return in_array($key, $this->included_modules ?? [], true);
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true)->where('is_active', true)->orderBy('sort');
    }
}
