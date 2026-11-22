<?php

namespace App\Domain\POS\Models;

use App\Domain\Team\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Child of Sale (reach it through the tenant-scoped sale). */
class SaleItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'tax_percent' => 'float'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function sellable(): MorphTo
    {
        return $this->morphTo();
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withoutGlobalScopes();
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function refundableQty(): int
    {
        return max(0, $this->qty - $this->refunded_qty);
    }

    /** Pro-rata amount charged for $qty units of this line. */
    public function amountFor(int $qty): int
    {
        return $qty >= $this->qty ? (int) $this->total : intdiv((int) $this->total * $qty, max(1, (int) $this->qty));
    }
}
