<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current stock per branch. NEVER update qty directly — use StockLedger::move().
 */
class BranchStock extends Model
{
    use BelongsToBranch, BelongsToTenant;

    protected $table = 'branch_stock';

    protected $guarded = ['id'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function available(): int
    {
        return max(0, $this->qty - $this->reserved);
    }

    public function isLow(): bool
    {
        return $this->qty <= $this->low_stock_threshold;
    }

    public function scopeLow($q)
    {
        return $q->whereColumn('qty', '<=', 'low_stock_threshold');
    }
}
