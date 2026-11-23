<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Models\Referral;
use App\Domain\Marketing\Support\LoyaltySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Links a new client to the friend who referred them (/r/{referral_code}) and gives the new client a personal,
 * single-use first-visit coupon. The referrer is rewarded later by RewardReferral.
 */
class RegisterReferral
{
    public function handle(Client $friend, string $referralCode): ?Referral
    {
        $referrer = Client::where('referral_code', strtoupper(trim($referralCode)))->first();
        if (! $referrer || $referrer->id === $friend->id || Referral::where('referred_client_id', $friend->id)->exists()) {
            return null;
        }
        if ((int) $friend->visits_count > 0) {
            return null; // existing clients cannot be "referred"
        }

        return DB::transaction(function () use ($friend, $referrer) {
            $friend->forceFill(['referred_by_client_id' => $referrer->id, 'source' => $friend->source ?: 'referral'])->save();
            $promotion = Promotion::create([
                'name' => ['en' => 'Welcome gift from '.$referrer->firstName(), 'ar' => 'هدية ترحيب من '.$referrer->firstName()],
                'type' => 'coupon',
                'code' => 'REF-'.strtoupper(Str::random(6)),
                'discount_type' => 'percent',
                'discount_value' => (int) LoyaltySettings::referral('friend_discount_bp'),
                'rules' => ['client_id' => $friend->id, 'first_visit' => true, 'applies_to' => 'services'],
                'usage_limit' => 1,
                'ends_at' => now()->addDays((int) LoyaltySettings::referral('friend_coupon_days')),
            ]);

            return Referral::create(['referrer_client_id' => $referrer->id, 'referred_client_id' => $friend->id, 'promotion_id' => $promotion->id]);
        });
    }
}
