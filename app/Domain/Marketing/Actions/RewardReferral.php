<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Models\Client;
use App\Domain\Loyalty\Actions\LoyaltyLedger;
use App\Domain\Marketing\Models\Referral;
use App\Domain\Marketing\Support\LoyaltySettings;

/** Referrer earns points once the referred friend completes their first visit. Idempotent ("referral:{id}"). */
class RewardReferral
{
    public function __construct(protected LoyaltyLedger $ledger) {}

    public function handle(Booking $booking): ?Referral
    {
        if (! $booking->client_id || ! tenant()?->hasModule('loyalty')) {
            return null;
        }
        $referral = Referral::where('referred_client_id', $booking->client_id)->where('status', 'pending')->first();
        $referrer = $referral ? Client::find($referral->referrer_client_id) : null;
        if (! $referral || ! $referrer) {
            return null;
        }
        $points = (int) LoyaltySettings::referral('referrer_points');
        if ($points > 0) {
            $this->ledger->award($referrer, $points, 'referral', $referral, 'referral:'.$referral->id, __('marketing.referrals.reward_note'));
        }
        $referral->update(['status' => 'rewarded', 'rewarded_at' => now(), 'first_booking_id' => $booking->id, 'points_awarded' => $points]);
        activity()->performedOn($referral)->withProperties(['points' => $points])->log('referral.rewarded');

        return $referral;
    }
}
