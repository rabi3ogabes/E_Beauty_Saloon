<?php

namespace App\Domain\AIStudio\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIWalletTransaction extends Model
{
    use BelongsToTenant;

    protected $table = 'ai_wallet_transactions';

    protected $guarded = ['id'];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(AIWallet::class, 'ai_wallet_id');
    }
}
