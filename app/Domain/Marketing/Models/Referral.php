<?php

namespace App\Domain\Marketing\Models;

use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending', 'points_awarded' => 0];

    protected function casts(): array
    {
        return ['rewarded_at' => 'datetime'];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'referrer_client_id')->withTrashed();
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'referred_client_id')->withTrashed();
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
