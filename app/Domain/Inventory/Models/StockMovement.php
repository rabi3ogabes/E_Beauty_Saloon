<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\ProductVariant;
use App\Models\User;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    use BelongsToBranch, BelongsToTenant;

    protected $table = 'stock_movements';

    protected $guarded = ['id'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
