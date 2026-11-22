<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Models\PromotionRedemption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Persists promotion usage after a sale/booking is confirmed (idempotent per promotion + reference).
 * Pass the "applied" array returned by ApplyPromotions.
 */
class RecordPromotionRedemption
{
    public function handle(array $applied, Model $reference, ?Client $client = null): void
    {
        DB::transaction(function () use ($applied, $reference, $client) {
            foreach ($applied as $row) {
                $key = 'promo:'.$row['promotion_id'].':'.$reference->getMorphClass().':'.$reference->getKey();
                if (PromotionRedemption::where('idempotency_key', $key)->exists()) {
                    continue;
                }
                PromotionRedemption::create([
                    'promotion_id' => $row['promotion_id'], 'client_id' => $client?->id,
                    'reference_type' => $reference->getMorphClass(), 'reference_id' => $reference->getKey(),
                    'discount_amount' => (int) $row['amount'], 'idempotency_key' => $key,
                ]);
                Promotion::whereKey($row['promotion_id'])->increment('used_count');
            }
        });
    }
}
