<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingCycle extends Model
{
    protected $table = 'billing_cycles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'float',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
