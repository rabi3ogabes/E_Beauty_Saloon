<?php

namespace App\Domain\AIStudio\Models;

use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A salon's AI credit wallet. Balance is denormalized from ai_wallet_transactions (CreditLedger). */
class AIWallet extends Model
{
    use BelongsToTenant;

    protected $table = 'ai_wallets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['auto_topup' => 'boolean', 'low_balance_notified_at' => 'datetime'];
    }

    public static function for(Tenant $tenant): self
    {
        return static::withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenant->id], [
            'balance' => 0,
            'monthly_included' => (int) ($tenant->currentSubscription?->plan?->included_ai_credits ?? 0),
        ]);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AIWalletTransaction::class, 'ai_wallet_id')->latest('id');
    }

    public function autoTopupPack(): BelongsTo
    {
        return $this->belongsTo(AICreditPack::class, 'auto_topup_pack_id');
    }

    public function isLow(): bool
    {
        return $this->balance <= $this->low_balance_threshold;
    }
}
