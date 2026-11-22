<?php

namespace App\Domain\Websites\Support;

use App\Domain\AIStudio\Models\Style;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\Team\Models\Staff;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;

/** Lazily-loaded, memoized data for salon page sections (only what a page actually renders is queried). */
class SiteData
{
    protected array $memo = [];

    public function __construct(public readonly Tenant $tenant) {}

    protected function once(string $key, \Closure $load): mixed
    {
        return $this->memo[$key] ??= $load();
    }

    public function branches(): Collection
    {
        return $this->once('branches', fn () => Branch::active()->orderBy('sort')->get());
    }

    public function categories(): Collection
    {
        return $this->once('categories', fn () => ServiceCategory::where('is_active', true)
            ->with(['services' => fn ($q) => $q->active()->where('online_bookable', true)->orderBy('sort')])
            ->orderBy('sort')->get()->filter(fn ($c) => $c->services->isNotEmpty())->values());
    }

    public function team(int $limit = 8): Collection
    {
        return $this->once('team'.$limit, fn () => Staff::bookable()->orderBy('sort')->limit($limit)->get());
    }

    public function products(int $limit = 4): Collection
    {
        return $this->once('products'.$limit, fn () => Product::query()->active()->where('sold_online', true)->with('defaultVariant')->latest('id')->limit($limit)->get());
    }

    public function studioStyles(int $limit = 6): Collection
    {
        return $this->once('styles'.$limit, fn () => class_exists(Style::class) ? Style::active()->limit($limit)->get() : collect());
    }

    public function gallery(array $settings): array
    {
        $images = array_values(array_filter((array) ($settings['images'] ?? [])));
        if ($images) {
            return $images;
        }

        return $this->branches()->flatMap(fn ($b) => (array) $b->gallery)->filter()->take(8)->values()->all();
    }

    public function bookUrl(?string $query = null): ?string
    {
        return $this->tenant->hasModule('booking') ? url('/book').($query ? '?'.$query : '') : null;
    }
}
