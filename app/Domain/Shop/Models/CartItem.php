<?php

namespace App\Domain\Shop\Models;

use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Child of Cart — only reached through a tenant-scoped cart. */
class CartItem extends Model
{
    protected $guarded = ['id'];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function lineTotal(): int
    {
        return $this->qty * ($this->variant?->effectivePrice() ?? 0);
    }
}
