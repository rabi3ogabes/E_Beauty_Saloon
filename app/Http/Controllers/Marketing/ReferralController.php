<?php

namespace App\Http\Controllers\Marketing;

use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Actions\RegisterReferral;
use App\Domain\Marketing\Support\LoyaltySettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * /r/{referral_code}: friend landing page. The code is remembered in the session; when the friend signs up
 * (client OTP login creates the Client) the referral and their first-visit coupon are created automatically.
 */
class ReferralController extends Controller
{
    public function __invoke(Request $request, string $code, RegisterReferral $register)
    {
        $referrer = Client::where('referral_code', strtoupper($code))->first();
        abort_unless($referrer, 404);

        $friend = $request->user('client');
        $referral = null;
        if ($friend && $friend->id !== $referrer->id) {
            $referral = $register->handle($friend, $code);
        } else {
            $request->session()->put('marketing.referral_code', strtoupper($code));
        }

        return view('marketing.site.referral', [
            'referrer' => $referrer,
            'discount' => (int) LoyaltySettings::referral('friend_discount_bp') / 100,
            'referral' => $referral?->load('promotion'),
            'friend' => $friend,
        ]);
    }
}
