<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Billing\Models\Module;
use App\Domain\Billing\Models\Plan;
use App\Http\Controllers\Controller;

class PlatformPageController extends Controller
{
    public function home()
    {
        return view('platform.home', [
            'plans' => Plan::public()->with('billingCycles')->get(),
            'modules' => Module::where('is_active', true)->orderBy('sort')->get(),
        ]);
    }
}
