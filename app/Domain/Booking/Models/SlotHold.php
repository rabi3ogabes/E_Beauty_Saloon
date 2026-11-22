<?php

namespace App\Domain\Booking\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SlotHold extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function scopeActive($q)
    {
        return $q->where('expires_at', '>', now())->whereNull('booking_id');
    }
}
