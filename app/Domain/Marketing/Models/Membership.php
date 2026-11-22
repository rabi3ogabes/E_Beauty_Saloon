<?php

namespace App\Domain\Marketing\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * Beauty-club plan. perks: {"included":[{"service_id":1,"quantity":2}],"product_discount_bp":1000,"service_discount_bp":0}
 * billing: recurring (gateway supports tokenised renewals) | prepaid (term_months paid upfront).
 */
class Membership extends Model
{
    use BelongsToTenant, HasTranslations, SoftDeletes;

    protected $guarded = ['id'];

    protected $attributes = ['billing' => 'prepaid', 'term_months' => 1, 'is_active' => true, 'sellable_online' => true];

    public array $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return ['perks' => 'array', 'sellable_online' => 'boolean', 'is_active' => 'boolean'];
    }

    public function clientMemberships(): HasMany
    {
        return $this->hasMany(ClientMembership::class);
    }

    public function productDiscountBp(): int
    {
        return (int) data_get($this->perks, 'product_discount_bp', 0);
    }

    public function serviceDiscountBp(): int
    {
        return (int) data_get($this->perks, 'service_discount_bp', 0);
    }
}
