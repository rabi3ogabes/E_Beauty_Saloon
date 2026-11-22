<?php

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class Product extends Model
{
    use BelongsToTenant, HasTranslations, SoftDeletes;

    protected $guarded = ['id'];

    public array $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'attributes' => 'array',
            'track_stock' => 'boolean',
            'sold_online' => 'boolean',
            'is_active' => 'boolean',
            'tax_percent' => 'float',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->orderByDesc('is_default')->orderBy('id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
