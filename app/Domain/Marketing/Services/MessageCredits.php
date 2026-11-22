<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Marketing\Models\MessageCreditTransaction;
use App\Domain\Marketing\Models\MessageCreditWallet;
use App\Domain\Marketing\Models\PlatformMessageChannel;
use App\Domain\Marketing\Models\TenantChannelSetting;
use Illuminate\Support\Facades\DB;

/**
 * Prepaid message credits for platform-shared WhatsApp/SMS. Balance and prices are integer minor units.
 * Every movement is a ledger row with an idempotency key.
 */
class MessageCredits
{
    public const INSUFFICIENT = 'insufficient_message_credits';

    public function wallet(int $tenantId): MessageCreditWallet
    {
        return MessageCreditWallet::firstOrCreate(['tenant_id' => $tenantId]);
    }

    public function balance(int $tenantId): int
    {
        return (int) (MessageCreditWallet::where('tenant_id', $tenantId)->value('balance') ?? 0);
    }

    public function topUp(int $tenantId, int $amount, ?string $note = null, ?string $key = null): MessageCreditTransaction
    {
        return $this->apply($tenantId, abs($amount), 'topup', null, $key, $note);
    }

    /** Returns false (and changes nothing) when the balance is insufficient — the hard stop. */
    public function debit(int $tenantId, int $amount, ?string $channel = null, ?string $key = null, ?string $reference = null): bool
    {
        try {
            $this->apply($tenantId, -abs($amount), 'debit', $channel, $key, null, $reference);

            return true;
        } catch (InsufficientCredits) {
            return false;
        }
    }

    public function refund(int $tenantId, int $amount, ?string $channel, string $debitKey): void
    {
        $this->apply($tenantId, abs($amount), 'refund', $channel, 'refund:'.$debitKey);
    }

    /** Channels of this tenant that are billed from the wallet. */
    public function sharedChannels(int $tenantId): array
    {
        return TenantChannelSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('mode', 'platform_shared')->where('is_active', true)->pluck('channel')->all();
    }

    /**
     * Cost estimate for sending $count messages on $channel (0 when the tenant uses its own credentials).
     */
    public function estimate(int $tenantId, string $channel, int $count): int
    {
        return in_array($channel, $this->sharedChannels($tenantId), true) ? PlatformMessageChannel::priceFor($channel) * $count : 0;
    }

    protected function apply(int $tenantId, int $amount, string $type, ?string $channel = null, ?string $key = null, ?string $note = null, ?string $reference = null): MessageCreditTransaction
    {
        return DB::transaction(function () use ($tenantId, $amount, $type, $channel, $key, $note, $reference) {
            if ($key && ($existing = MessageCreditTransaction::where('idempotency_key', $key)->first())) {
                return $existing;
            }
            $this->wallet($tenantId);
            $wallet = MessageCreditWallet::where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();
            $balance = $wallet->balance + $amount;
            if ($balance < 0) {
                throw new InsufficientCredits;
            }
            $wallet->update(['balance' => $balance]);

            return MessageCreditTransaction::create([
                'tenant_id' => $tenantId, 'type' => $type, 'amount' => $amount, 'balance_after' => $balance,
                'channel' => $channel, 'reference' => $reference, 'idempotency_key' => $key, 'note' => $note, 'user_id' => auth()->id(),
            ]);
        });
    }
}
