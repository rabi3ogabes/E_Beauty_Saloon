<?php

namespace App\Domain\Marketing\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PromotionRedemption extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
