<?php

namespace App\Domain\Payments\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TenantPaymentGateway extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $hidden = ['credentials', 'webhook_secret'];

    public const DRIVERS = ['sadad', 'skipcash', 'myfatoorah', 'tap', 'stripe', 'cash', 'card_terminal', 'bank_transfer', 'cod'];

    /** Drivers that take money online via a hosted page. */
    public const ONLINE_DRIVERS = ['sadad', 'skipcash', 'myfatoorah', 'tap', 'stripe'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'enabled_for' => 'array',
            'branch_ids' => 'array',
            'is_active' => 'boolean',
            'last_test_ok' => 'boolean',
            'last_tested_at' => 'datetime',
            'fee_percent' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $g) => $g->webhook_secret ??= Str::random(48));
    }

    public function isOnline(): bool
    {
        return in_array($this->driver, self::ONLINE_DRIVERS, true);
    }

    public function enabledFor(string $context, ?int $branchId = null): bool
    {
        return $this->is_active
            && in_array($context, $this->enabled_for ?? [], true)
            && ($branchId === null || empty($this->branch_ids) || in_array($branchId, $this->branch_ids));
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials ?? [], $key, $default);
    }

    public function webhookUrl(): string
    {
        return route('webhooks.tenant-gateway', ['gateway' => $this->id, 'secret' => $this->webhook_secret]);
    }

    public function scopeFor($q, string $context, ?int $branchId = null)
    {
        return $q->where('is_active', true)->orderBy('sort')->get()->filter->enabledFor($context, $branchId)->values();
    }
}
