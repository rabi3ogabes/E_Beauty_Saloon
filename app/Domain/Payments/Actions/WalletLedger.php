<?php

namespace App\Domain\Payments\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\Payments\Models\WalletTransaction;
use App\Support\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Client prepaid wallet / store credit. Balance is denormalized on clients.wallet_balance. */
class WalletLedger
{
    public function credit(Client $client, int $amount, string $type = 'topup', ?Model $reference = null, ?string $key = null, ?string $note = null): WalletTransaction
    {
        return $this->apply($client, abs($amount), $type, $reference, $key, $note);
    }

    public function debit(Client $client, int $amount, string $type = 'spend', ?Model $reference = null, ?string $key = null, ?string $note = null): WalletTransaction
    {
        return $this->apply($client, -abs($amount), $type, $reference, $key, $note);
    }

    protected function apply(Client $client, int $amount, string $type, ?Model $reference, ?string $key, ?string $note): WalletTransaction
    {
        return DB::transaction(function () use ($client, $amount, $type, $reference, $key, $note) {
            if ($key && ($existing = WalletTransaction::withoutGlobalScopes()->where('idempotency_key', $key)->first())) {
                return $existing;
            }
            $locked = Client::withoutGlobalScopes()->lockForUpdate()->findOrFail($client->id);
            $balance = $locked->wallet_balance + $amount;
            if ($balance < 0) {
                throw new DomainException(__('salon.errors.insufficient_wallet', ['balance' => money($locked->wallet_balance)]));
            }
            $locked->update(['wallet_balance' => $balance]);
            $client->wallet_balance = $balance;

            return WalletTransaction::withoutGlobalScopes()->create([
                'tenant_id' => $locked->tenant_id, 'client_id' => $locked->id, 'type' => $type,
                'amount' => $amount, 'balance_after' => $balance,
                'reference_type' => $reference?->getMorphClass(), 'reference_id' => $reference?->getKey(),
                'idempotency_key' => $key, 'note' => $note, 'user_id' => auth()->id(),
            ]);
        });
    }
}
