<?php

namespace App\Domain\Marketing\Models;

use App\Domain\Catalog\Models\Service;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * A bundle (e.g. "Bridal Glow: hair + makeup + nails") or a session pack ("6 blowouts").
 * Price is fixed, or the items' value minus discount_bp when pricing = percent.
 */
class Package extends Model
{
    use BelongsToTenant, HasTranslations, SoftDeletes;

    protected $guarded = ['id'];

    protected $attributes = ['pricing' => 'fixed', 'price' => 0, 'discount_bp' => 0, 'sessions_count' => 1, 'is_active' => true, 'sellable_online' => true, 'sellable_pos' => true];

    public array $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return ['branch_ids' => 'array', 'sellable_online' => 'boolean', 'sellable_pos' => 'boolean', 'is_active' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PackageItem::class);
    }

    public function clientPackages(): HasMany
    {
        return $this->hasMany(ClientPackage::class);
    }

    /** Sum of the included items' regular prices. */
    public function itemsValue(): int
    {
        return (int) $this->items->sum(function (PackageItem $i) {
            $item = $i->itemable;
            $price = $item instanceof Service ? (int) $item->price : (int) ($item?->sale_price ?? $item?->price ?? 0);

            return $price * $i->quantity;
        });
    }

    public function effectivePrice(): int
    {
        if ($this->pricing === 'percent') {
            return (int) round($this->itemsValue() * (10000 - (int) $this->discount_bp) / 10000);
        }

        return (int) $this->price;
    }

    public function savings(): int
    {
        return max(0, $this->itemsValue() - $this->effectivePrice());
    }

    public function coversService(int $serviceId): bool
    {
        return $this->items->contains(fn (PackageItem $i) => $i->itemable_type === (new Service)->getMorphClass() && (int) $i->itemable_id === $serviceId);
    }

    public function scopeOnline($q)
    {
        return $q->where('is_active', true)->where('sellable_online', true);
    }
}
