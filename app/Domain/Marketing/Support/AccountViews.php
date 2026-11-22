<?php

namespace App\Domain\Marketing\Support;

use App\Domain\CRM\Models\Client;
use App\Domain\Loyalty\Actions\LoyaltyLedger;
use App\Domain\Loyalty\Models\LoyaltyEntry;
use App\Domain\Marketing\Models\ClientMembership;
use App\Domain\Marketing\Models\ClientPackage;
use App\Domain\Marketing\Models\Referral;

/** Renders the client "My Account" sections owned by Marketing / Loyalty. */
final class AccountViews
{
    public static function packages(Client $client): string
    {
        return view('marketing.account.packages', [
            'client' => $client,
            'packages' => ClientPackage::with(['package.items', 'usages'])->where('client_id', $client->id)->whereIn('status', ['active', 'used_up', 'expired'])->latest('id')->get(),
            'memberships' => ClientMembership::with('membership')->where('client_id', $client->id)->latest('id')->get(),
        ])->render();
    }

    public static function loyalty(Client $client): string
    {
        $lifetime = (int) LoyaltyEntry::where('client_id', $client->id)->where('points', '>', 0)->sum('points');

        return view('marketing.account.loyalty', [
            'client' => $client,
            'entries' => LoyaltyEntry::where('client_id', $client->id)->latest('id')->limit(20)->get(),
            'lifetime' => $lifetime,
            'tiers' => LoyaltySettings::tiers(),
            'current' => LoyaltySettings::tier($client->loyalty_tier ?: 'silver'),
            'next' => LoyaltySettings::nextTier($lifetime),
            'value' => app(LoyaltyLedger::class)->valueOf((int) $client->loyalty_points),
        ])->render();
    }

    public static function referrals(Client $client): string
    {
        return view('marketing.account.referrals', [
            'client' => $client,
            'link' => MarketingUrl::to('/r/'.$client->referral_code),
            'referrals' => Referral::with('referred')->where('referrer_client_id', $client->id)->latest('id')->get(),
            'points' => (int) LoyaltySettings::referral('referrer_points'),
            'discount' => (int) LoyaltySettings::referral('friend_discount_bp') / 100,
        ])->render();
    }
}
