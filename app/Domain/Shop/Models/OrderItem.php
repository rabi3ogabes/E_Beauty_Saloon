<?php

namespace App\Domain\Shop\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/** Child of Order — only reached through a tenant-scoped order. */
class OrderItem extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    public array $translatable = ['name'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function label(?string $locale = null): string
    {
        $name = $this->getTranslation('name', $locale ?? app()->getLocale());

        return trim($name.($this->variant_name ? ' — '.$this->variant_name : ''));
    }
}
