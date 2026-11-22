<?php

namespace App\Domain\Payments\Models;

use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'paid_at' => 'datetime'];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(TenantPaymentGateway::class, 'tenant_payment_gateway_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'parent_payment_id');
    }

    public function isSucceeded(): bool
    {
        return in_array($this->status, ['succeeded', 'partially_refunded'], true);
    }

    public function refundable(): int
    {
        return max(0, $this->amount - $this->refunded_amount);
    }

    public function scopeSucceeded($q)
    {
        return $q->whereIn('status', ['succeeded', 'partially_refunded']);
    }
}
