<?php

namespace App\Http\Controllers\Marketing;

use App\Domain\Marketing\Actions\PurchasePackage;
use App\Domain\Marketing\Models\Membership;
use App\Domain\Marketing\Models\Package;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PackageShopController extends Controller
{
    public function index(Request $request)
    {
        return view('marketing.site.packages', [
            'packages' => Package::online()->with('items.itemable')->orderBy('sort')->get(),
            'memberships' => Membership::where('is_active', true)->where('sellable_online', true)->orderBy('sort')->get(),
            'client' => $request->user('client'),
        ]);
    }

    public function buy(Request $request, Package $package, PurchasePackage $purchase)
    {
        [$clientPackage, $payment] = $purchase->handle($package, $request->user('client'), 'online', url('/account/packages'));
        if ($payment) {
            $payment->update(['meta' => array_merge($payment->meta ?? [], ['return_to' => url('/account/packages')])]);

            return redirect()->away($payment->checkout_url);
        }

        return redirect('/account/packages')->with('status', __('marketing.packages.activated'));
    }
}
