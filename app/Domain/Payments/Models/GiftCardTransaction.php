<?php

namespace App\Domain\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GiftCardTransaction extends Model
{
    protected $table = 'gift_card_transactions';

    protected $guarded = ['id'];

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
