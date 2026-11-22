<?php

namespace App\Domain\Inventory\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use BelongsToTenant;

    protected $table = 'stock_transfers';

    protected $guarded = ['id'];

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Branches\Models\Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Branches\Models\Branch::class, 'to_branch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }
}
