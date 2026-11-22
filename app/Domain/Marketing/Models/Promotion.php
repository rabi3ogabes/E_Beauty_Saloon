<?php

namespace App\Domain\Marketing\Models;

use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * Promotion rules (JSON) by type — evaluated by ApplyPromotions:
 *   common:     applies_to: all|services|products, service_ids:[], product_ids:[], min_subtotal (minor), client_id (personal coupon)
 *   happy_hour: weekdays:[0..6] (0 = Sunday), from:"10:00", to:"13:00"   (branch local time)
 *   birthday:   days_before:7, days_after:7
 *   first_visit: (no extra rules — client has no completed visit)
 *   flash_sale: uses starts_at/ends_at, show_countdown
 *   bogo:       buy_quantity:2, get_quantity:1, get_discount_bp:10000 (cheapest eligible items discounted)
 */
class Promotion extends Model
{
    use BelongsToTenant, HasTranslations;

    public const TYPES = ['coupon', 'happy_hour', 'first_visit', 'birthday', 'flash_sale', 'bogo'];

    protected $guarded = ['id'];

    protected $attributes = ['is_active' => true, 'used_count' => 0, 'discount_type' => 'percent', 'discount_value' => 0];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'branch_ids' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'show_countdown' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $p) {
            $p->code = $p->code ? strtoupper(trim($p->code)) : null;
        });
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    public function rule(string $key, mixed $default = null): mixed
    {
        return data_get($this->rules ?? [], $key, $default);
    }

    public function isLive(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->lte($at))
            && ($this->ends_at === null || $this->ends_at->gt($at))
            && ($this->usage_limit === null || $this->used_count < $this->usage_limit);
    }

    public function scopeLive($q)
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** Human label of the discount, e.g. "15%" or "QAR 50.00". */
    public function discountLabel(): string
    {
        return $this->discount_type === 'percent' ? rtrim(rtrim(number_format($this->discount_value / 100, 2), '0'), '.').'%' : money((int) $this->discount_value);
    }
}
