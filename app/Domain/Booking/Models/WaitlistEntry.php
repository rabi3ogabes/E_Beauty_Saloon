<?php

namespace App\Domain\Booking\Models;

use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitlistEntry extends Model
{
    use BelongsToBranch, BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['service_ids' => 'array', 'date_from' => 'date', 'date_to' => 'date', 'offered_at' => 'datetime', 'offered_starts_at' => 'datetime', 'offer_expires_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
