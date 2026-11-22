<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Models\GiftCard;
use App\Domain\Payments\Models\GiftCardTransaction;
use App\Support\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class GiftCardLedger
{
    public function findUsable(string $code): GiftCard
    {
        $card = GiftCard::where('code', strtoupper(trim($code)))->first();
        if (! $card || ! $card->isUsable()) {
            throw new DomainException(__('salon.errors.gift_card_invalid'));
        }

        return $card;
    }

    public function redeem(GiftCard $card, int $amount, ?Model $reference = null, ?string $key = null): GiftCardTransaction
    {
        return $this->apply($card, -abs($amount), $reference, $key);
    }

    public function refund(GiftCard $card, int $amount, ?Model $reference = null, ?string $key = null): GiftCardTransaction
    {
        return $this->apply($card, abs($amount), $reference, $key);
    }

    protected function apply(GiftCard $card, int $amount, ?Model $reference, ?string $key): GiftCardTransaction
    {
        return DB::transaction(function () use ($card, $amount, $reference, $key) {
            if ($key && ($existing = GiftCardTransaction::where('idempotency_key', $key)->first())) {
                return $existing;
            }
            $locked = GiftCard::withoutGlobalScopes()->lockForUpdate()->findOrFail($card->id);
            $balance = $locked->balance + $amount;
            if ($balance < 0) {
                throw new DomainException(__('salon.errors.gift_card_balance', ['balance' => money($locked->balance, $locked->currency)]));
            }
            $locked->update(['balance' => $balance, 'status' => $balance === 0 ? 'redeemed' : 'active']);
            $card->balance = $balance;

            return $locked->transactions()->create([
                'amount' => $amount, 'balance_after' => $balance,
                'reference_type' => $reference?->getMorphClass(), 'reference_id' => $reference?->getKey(),
                'idempotency_key' => $key,
            ]);
        });
    }
}
