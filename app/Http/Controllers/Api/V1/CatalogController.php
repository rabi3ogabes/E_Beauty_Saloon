<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\Team\Models\Staff;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BranchResource;
use App\Http\Resources\V1\ServiceResource;
use App\Http\Resources\V1\StaffResource;
use Illuminate\Http\Request;

/**
 * @group Salon catalog
 *
 * Public, cacheable salon information (ETag supported).
 *
 * @unauthenticated
 */
class CatalogController extends Controller
{
    public function salon()
    {
        $t = tenant();

        return response()->json(['data' => [
            'name' => $t->name,
            'slug' => $t->slug,
            'currency' => $t->currency,
            'timezone' => $t->timezone,
            'locales' => array_keys(config('salon.locales')),
            'modules' => $t->entitledModules(),
            'branding' => collect($t->branding ?? [])->only(['primary', 'accent', 'ink', 'background', 'font_display', 'font_body', 'button_style', 'about', 'social'])
                ->merge(['logo_url' => $t->brand('logo_path') ? asset('storage/'.$t->brand('logo_path')) : null]),
        ]]);
    }

    public function branches()
    {
        return BranchResource::collection(Branch::active()->orderBy('sort')->get());
    }

    public function categories()
    {
        return response()->json(['data' => ServiceCategory::where('is_active', true)->orderBy('sort')->get()
            ->map(fn ($c) => ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->name, 'icon' => $c->icon])]);
    }

    public function services(Request $request)
    {
        $request->validate(['branch_id' => ['nullable', 'integer'], 'category_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:60']]);
        $branch = $request->integer('branch_id') ? Branch::findOrFail($request->integer('branch_id')) : null;
        $request->attributes->set('api_branch', $branch);

        $services = Service::active()->where('online_bookable', true)
            ->with(['variants', 'addons', 'branches'])
            ->when($request->integer('category_id'), fn ($q, $id) => $q->where('service_category_id', $id))
            ->when($request->string('q')->toString(), fn ($q, $term) => $q->where(fn ($w) => $w->where('name->en', 'like', "%{$term}%")->orWhere('name->ar', 'like', "%{$term}%")))
            ->orderBy('sort')->paginate(min(100, $request->integer('per_page', 50)));

        if ($branch) {
            $services->setCollection($services->getCollection()->filter->isActiveAt($branch)->values());
        }

        return ServiceResource::collection($services);
    }

    public function service(Request $request, Service $service)
    {
        $request->attributes->set('api_branch', $request->integer('branch_id') ? Branch::find($request->integer('branch_id')) : null);

        return new ServiceResource($service->load(['variants', 'addons']));
    }

    public function staff(Request $request)
    {
        return StaffResource::collection(Staff::bookable()->with(['branches:id', 'services:id'])
            ->when($request->integer('branch_id'), fn ($q, $id) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $id)))
            ->when($request->integer('service_id'), fn ($q, $id) => $q->whereHas('services', fn ($s) => $s->where('services.id', $id)))
            ->orderBy('sort')->get());
    }
}
