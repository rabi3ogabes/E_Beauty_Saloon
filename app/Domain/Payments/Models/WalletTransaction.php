<?php

namespace App\Domain\Payments\Models;

use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WalletTransaction extends Model
{
    use BelongsToTenant;

    protected $table = 'wallet_transactions';

    protected $guarded = ['id'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
