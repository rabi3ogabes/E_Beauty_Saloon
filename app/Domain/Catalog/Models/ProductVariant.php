<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Inventory\Models\BranchStock;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class ProductVariant extends Model
{
    use BelongsToTenant, HasTranslations;

    protected $guarded = ['id'];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stock(): HasMany
    {
        return $this->hasMany(BranchStock::class);
    }

    public function effectivePrice(): int
    {
        return (int) ($this->sale_price ?? $this->price);
    }

    public function availableAt(int $branchId): int
    {
        $row = $this->stock()->withoutGlobalScopes()->where('branch_id', $branchId)->first();

        return $row ? max(0, $row->qty - $row->reserved) : 0;
    }

    public function displayName(): string
    {
        $variant = $this->getTranslation('name', app()->getLocale(), false);

        return trim($this->product?->name.($variant ? ' — '.$variant : ''));
    }
}
