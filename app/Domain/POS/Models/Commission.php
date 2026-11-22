<?php

namespace App\Domain\POS\Models;

use App\Domain\Team\Models\Staff;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    use BelongsToBranch, BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rule_snapshot' => 'array'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withoutGlobalScopes()->withTrashed();
    }
}
