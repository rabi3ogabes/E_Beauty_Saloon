<?php

namespace App\Domain\POS\Models;

use App\Domain\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Links a sale to a core `payments` row and keeps the counter details (tendered, change). */
class SalePayment extends Model
{
    protected $guarded = ['id'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withoutGlobalScopes();
    }
}
