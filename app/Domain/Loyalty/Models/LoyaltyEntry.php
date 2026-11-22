<?php

namespace App\Domain\Loyalty\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LoyaltyEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'loyalty_ledger';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\CRM\Models\Client::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
