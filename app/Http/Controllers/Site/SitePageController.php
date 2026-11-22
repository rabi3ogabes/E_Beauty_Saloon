<?php

namespace App\Http\Controllers\Site;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\Team\Models\Staff;
use App\Http\Controllers\Controller;

class SitePageController extends Controller
{
    public function home()
    {
        return view('site.home', [
            'tenant' => tenant(),
            'branches' => Branch::active()->orderBy('sort')->get(),
            'categories' => ServiceCategory::where('is_active', true)->with(['services' => fn ($q) => $q->active()->where('online_bookable', true)->orderBy('sort')])->orderBy('sort')->get(),
            'team' => Staff::bookable()->orderBy('sort')->limit(8)->get(),
        ]);
    }
}
