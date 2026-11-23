<?php

namespace App\Domain\Shop\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopCoupon extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'is_active' => 'boolean', 'free_shipping' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $c) => $c->code = strtoupper(trim((string) $c->code)));
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Discount in minor units for a merchandise subtotal. Percent values are basis points. */
    public function discountFor(int $subtotal): int
    {
        $discount = $this->type === 'percent'
            ? intdiv($subtotal * (int) $this->value + 5000, 10000)
            : (int) $this->value;
        if ($this->type === 'percent' && $this->max_discount) {
            $discount = min($discount, (int) $this->max_discount);
        }

        return max(0, min($discount, $subtotal));
    }
}
