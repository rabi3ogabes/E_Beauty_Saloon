<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Models\GiftCard;
use Illuminate\Support\Facades\DB;

/**
 * Issues a gift card (sold at POS, online, or granted). Status starts "pending" until paid
 * (call activate()), or "active" immediately when $paid = true.
 */
class IssueGiftCard
{
    public function handle(int $amount, array $recipient = [], ?int $purchaserClientId = null, string $design = 'champagne', bool $paid = true, ?int $validMonths = 12): GiftCard
    {
        return DB::transaction(function () use ($amount, $recipient, $purchaserClientId, $design, $paid, $validMonths) {
            $card = GiftCard::create([
                'initial_amount' => $amount,
                'balance' => $amount,
                'currency' => tenant()?->currency ?? 'QAR',
                'purchaser_client_id' => $purchaserClientId,
                'recipient_name' => $recipient['name'] ?? null,
                'recipient_phone' => $recipient['phone'] ?? null,
                'recipient_email' => $recipient['email'] ?? null,
                'message' => $recipient['message'] ?? null,
                'design' => $design,
                'status' => $paid ? 'active' : 'pending',
                'expires_at' => $validMonths ? now()->addMonths($validMonths) : null,
            ]);
            $card->transactions()->create(['amount' => $amount, 'balance_after' => $amount, 'idempotency_key' => 'gc-issue-'.$card->id]);
            activity()->performedOn($card)->withProperties(['amount' => $amount])->log('gift_card.issued');

            return $card;
        });
    }

    public function activate(GiftCard $card): GiftCard
    {
        if ($card->status === 'pending') {
            $card->update(['status' => 'active']);
        }

        return $card;
    }
}
