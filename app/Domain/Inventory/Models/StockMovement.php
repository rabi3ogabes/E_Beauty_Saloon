<?php

namespace App\Domain\Inventory\Models;

use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    use BelongsToTenant, BelongsToBranch;

    protected $table = 'stock_movements';

    protected $guarded = ['id'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Catalog\Models\ProductVariant::class, 'product_variant_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
