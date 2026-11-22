<?php

namespace App\Http\Controllers\Site;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\Websites\Models\SitePage;
use App\Domain\Websites\Support\SectionRegistry;
use App\Domain\Websites\Support\SiteData;
use App\Domain\Websites\Support\SiteSeo;
use App\Http\Controllers\Controller;

/** Salon website pages rendered from the section builder (with built-in defaults). */
class SitePageController extends Controller
{
    public function home()
    {
        $tenant = tenant();
        $page = SitePage::query()->where('is_home', true)->where('is_published', true)->with('visibleSections')->first();
        $sections = ($page && $page->visibleSections->isNotEmpty()) ? $page->visibleSections : SectionRegistry::defaultHome($tenant);

        return view('site.home', [
            'tenant' => $tenant,
            'page' => $page,
            'sections' => $sections->filter(fn ($s) => SectionRegistry::available($s, $tenant))->values(),
            'data' => new SiteData($tenant),
        ]);
    }

    /** Custom builder pages: /p/{slug} */
    public function page(string $slug)
    {
        $tenant = tenant();
        $page = SitePage::query()->where('slug', $slug)->where('is_home', false)->where('is_published', true)->with('visibleSections')->firstOrFail();

        return view('site.website.page', [
            'tenant' => $tenant,
            'page' => $page,
            'sections' => $page->visibleSections->filter(fn ($s) => SectionRegistry::available($s, $tenant))->values(),
            'data' => new SiteData($tenant),
        ]);
    }

    /** SEO landing page per branch: "Hair salon in West Bay" with LocalBusiness / BeautySalon JSON-LD. */
    public function branch(string $slug)
    {
        $tenant = tenant();
        $branch = Branch::active()->where('slug', $slug)->firstOrFail();
        $categories = ServiceCategory::where('is_active', true)->orderBy('sort')
            ->with(['services' => fn ($q) => $q->active()->where('online_bookable', true)->whereHas('branches', fn ($b) => $b->withoutGlobalScopes()->where('branches.id', $branch->id)->where('branch_service.is_active', true))->with('branches')->orderBy('sort')])
            ->get()->filter(fn ($c) => $c->services->isNotEmpty())->values();
        $services = $categories->flatMap->services;

        return view('site.website.branch', [
            'tenant' => $tenant,
            'branch' => $branch,
            'categories' => $categories,
            'team' => $branch->staff()->where('is_active', true)->where('is_bookable', true)->orderBy('sort')->limit(8)->get(),
            'jsonLd' => SiteSeo::branch($tenant, $branch, $services->take(20)),
            'others' => Branch::active()->where('id', '!=', $branch->id)->orderBy('sort')->get(),
            'gbp' => (string) $tenant->setting('website.gbp_booking_url', ''),
        ]);
    }

    public function offline()
    {
        return view('site.website.offline', ['tenant' => tenant()]);
    }

    /** Helper used by views: a service's display price at a branch. */
    public static function priceAt(Service $service, ?Branch $branch = null): int
    {
        return $service->priceAt($branch);
    }
}
