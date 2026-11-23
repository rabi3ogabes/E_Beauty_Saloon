<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Events\AICreditsLow;
use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\AIStudio\Models\AIWalletTransaction;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The only way to move AI credits. Signed transactions with idempotency keys; the wallet balance
 * is updated under a row lock in the same transaction.
 */
class CreditLedger
{
    public function credit(Tenant $tenant, int $credits, string $type, string $idempotencyKey, ?string $description = null, ?string $refType = null, ?int $refId = null): AIWalletTransaction
    {
        return $this->move($tenant, abs($credits), $type, $idempotencyKey, $description, $refType, $refId);
    }

    /** @throws DomainException when the balance is insufficient (unless $allowNegative). */
    public function debit(Tenant $tenant, int $credits, string $type, string $idempotencyKey, ?string $description = null, ?string $refType = null, ?int $refId = null, bool $allowNegative = false): AIWalletTransaction
    {
        return $this->move($tenant, -abs($credits), $type, $idempotencyKey, $description, $refType, $refId, $allowNegative);
    }

    public function balance(Tenant $tenant): int
    {
        return (int) AIWallet::for($tenant)->balance;
    }

    protected function move(Tenant $tenant, int $amount, string $type, string $key, ?string $description, ?string $refType, ?int $refId, bool $allowNegative = true): AIWalletTransaction
    {
        $tx = DB::transaction(function () use ($tenant, $amount, $type, $key, $description, $refType, $refId, $allowNegative) {
            if ($existing = AIWalletTransaction::withoutGlobalScopes()->where('idempotency_key', $key)->first()) {
                return $existing;
            }
            $wallet = AIWallet::for($tenant);
            $wallet = AIWallet::withoutGlobalScopes()->lockForUpdate()->findOrFail($wallet->id);

            if ($amount < 0 && ! $allowNegative && $wallet->balance + $amount < 0) {
                throw new DomainException(__('ai_studio.errors.insufficient_credits'));
            }

            $wallet->balance += $amount;
            if ($amount > 0 && $wallet->balance > $wallet->low_balance_threshold) {
                $wallet->low_balance_notified_at = null; // re-arm the low balance alert
            }
            $wallet->save();

            return AIWalletTransaction::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'ai_wallet_id' => $wallet->id,
                'amount' => $amount,
                'balance_after' => $wallet->balance,
                'type' => $type,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'idempotency_key' => $key,
                'description' => $description,
            ]);
        });

        if ($amount < 0) {
            $wallet = AIWallet::for($tenant);
            if ($wallet->isLow() && ! $wallet->low_balance_notified_at) {
                $wallet->forceFill(['low_balance_notified_at' => now()])->save();
                AICreditsLow::dispatch($tenant->id, (int) $wallet->balance);
            }
        }

        return $tx;
    }
}
