<?php

namespace App\Domain\Shop\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['gift_wrap' => 'boolean', 'last_activity_at' => 'datetime', 'abandoned_notified_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now(), 'abandoned_notified_at' => null])->save();
    }

    public function contactPhone(): ?string
    {
        return $this->client?->phone ?? $this->phone;
    }
}
