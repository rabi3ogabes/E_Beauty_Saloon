<?php

namespace App\Domain\Shop\Support;

use App\Domain\Booking\Models\Booking;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\Shop\Models\StaffPick;
use App\Domain\Team\Models\Staff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Storefront queries: listing with filters/search/sort, related, routine upsell, stylist picks. */
class ProductCatalog
{
    public const SORTS = ['featured', 'newest', 'price_asc', 'price_desc', 'name'];

    public function base(): Builder
    {
        return Product::query()->active()->where('sold_online', true)->whereIn('usage', ['retail', 'both'])
            ->with(['brand', 'category', 'variants']);
    }

    public function search(array $filters): Builder
    {
        $q = $this->base()->addSelect(['products.*', 'min_price' => ProductVariant::selectRaw('min(coalesce(sale_price, price))')->whereColumn('product_id', 'products.id')]);

        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $this->applySearch($q, $term);
        }
        if ($cat = $filters['category'] ?? null) {
            $ids = ProductCategory::where('slug', $cat)->pluck('id');
            $ids = $ids->merge(ProductCategory::whereIn('parent_id', $ids)->pluck('id'));
            $q->whereIn('product_category_id', $ids->all() ?: [0]);
        }
        if ($brands = array_filter((array) ($filters['brand'] ?? []))) {
            $q->whereHas('brand', fn ($b) => $b->whereIn('slug', $brands));
        }
        foreach (['hair_type', 'skin_type'] as $attr) {
            if ($v = $filters[$attr] ?? null) {
                $q->where(fn ($w) => $w->where("attributes->{$attr}", $v)->orWhere("attributes->{$attr}", 'all'));
            }
        }
        $min = isset($filters['min']) && $filters['min'] !== '' ? (int) round(((float) $filters['min']) * 100) : null;
        $max = isset($filters['max']) && $filters['max'] !== '' ? (int) round(((float) $filters['max']) * 100) : null;
        if ($min !== null || $max !== null) {
            $q->whereHas('variants', function ($v) use ($min, $max) {
                if ($min !== null) {
                    $v->whereRaw('coalesce(sale_price, price) >= ?', [$min]);
                }
                if ($max !== null) {
                    $v->whereRaw('coalesce(sale_price, price) <= ?', [$max]);
                }
            });
        }
        if (ShopSettings::get('hide_out_of_stock')) {
            $this->onlyInStock($q);
        }

        match ($filters['sort'] ?? 'featured') {
            'newest' => $q->latest('products.id'),
            'price_asc' => $q->orderBy('min_price')->orderBy('products.id'),
            'price_desc' => $q->orderByDesc('min_price')->orderBy('products.id'),
            'name' => $q->orderBy('products.slug'),
            default => $q->orderByDesc(DB::raw('case when exists (select 1 from product_variants pv where pv.product_id = products.id and pv.sale_price is not null) then 1 else 0 end'))->orderBy('products.id'),
        };

        return $q;
    }

    public function applySearch(Builder $q, string $term): Builder
    {
        $tokens = ArabicNormalizer::tokens($term);
        if (! $tokens) {
            return $q;
        }
        $ids = DB::table('shop_search_index')->where('tenant_id', tenant()?->id);
        foreach ($tokens as $token) {
            $ids->where('content', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $token).'%');
        }

        return $q->whereIn('products.id', $ids->select('product_id'));
    }

    public function onlyInStock(Builder $q): Builder
    {
        return $q->whereExists(fn ($e) => $e->selectRaw('1')->from('branch_stock')->join('product_variants', 'product_variants.id', '=', 'branch_stock.product_variant_id')
            ->whereColumn('product_variants.product_id', 'products.id')->whereRaw('branch_stock.qty - branch_stock.reserved > 0'));
    }

    /** Facets for the filter drawer. */
    public function facets(): array
    {
        $attrs = Product::active()->where('sold_online', true)->pluck('attributes');

        return [
            'categories' => ProductCategory::orderBy('sort')->get(),
            'brands' => Brand::orderBy('name')->get(),
            'hair_types' => $attrs->pluck('hair_type')->filter()->reject(fn ($v) => $v === 'all')->unique()->sort()->values(),
            'skin_types' => $attrs->pluck('skin_type')->filter()->reject(fn ($v) => $v === 'all')->unique()->sort()->values(),
        ];
    }

    public function related(Product $product, int $limit = 4): Collection
    {
        return $this->base()->whereKeyNot($product->id)
            ->where(fn ($q) => $q->where('product_category_id', $product->product_category_id)->orWhere('brand_id', $product->brand_id))
            ->orderByRaw('case when product_category_id = ? then 0 else 1 end', [$product->product_category_id ?? 0])
            ->limit($limit)->get();
    }

    /** "Complete the routine": same brand or category as what's in the cart, not already in it. */
    public function routineFor(Collection $products, int $limit = 3): Collection
    {
        if ($products->isEmpty()) {
            return collect();
        }

        return $this->base()->whereNotIn('products.id', $products->pluck('id'))
            ->where(fn ($q) => $q->whereIn('brand_id', $products->pluck('brand_id')->filter())->orWhereIn('product_category_id', $products->pluck('product_category_id')->filter()))
            ->inRandomOrder()->limit($limit)->get();
    }

    /** Services this product is used in: explicit mapping, else services whose category matches the product category. */
    public function usedInServices(Product $product, int $limit = 4): Collection
    {
        $ids = DB::table('shop_product_service')->where('product_id', $product->id)->pluck('service_id');
        $q = Service::query()->where('is_active', true)->with('category');
        if ($ids->isNotEmpty()) {
            return $q->whereIn('id', $ids)->limit($limit)->get();
        }
        $slug = (string) $product->category?->slug;
        if ($slug === '') {
            return collect();
        }
        $prefix = explode('-', $slug)[0];

        return $q->whereHas('category', fn ($c) => $c->whereIn('slug', [$slug, $prefix]))->orderBy('sort')->limit($limit)->get();
    }

    /** "Recommended by your stylist": picks of the staff from the client's most recent booking. */
    public function stylistPicks(?Client $client, int $limit = 4): array
    {
        if (! $client) {
            return [null, collect()];
        }
        $booking = Booking::query()->where('client_id', $client->id)->where('starts_at', '<=', now())
            ->whereNotIn('status', ['cancelled_by_client', 'cancelled_by_salon', 'no_show'])
            ->latest('starts_at')->with(['items' => fn ($q) => $q->whereNotNull('staff_id')])->first();
        $item = $booking?->items->first();
        if (! $item) {
            return [null, collect()];
        }
        $staff = Staff::withoutGlobalScopes()->find($item->staff_id);
        $productIds = StaffPick::where('staff_id', $item->staff_id)->orderBy('sort')->pluck('product_id');
        if ($productIds->isEmpty()) {
            // Fallback: products matching the category of the service she performed.
            $serviceCat = (string) Service::with('category')->find($item->service_id)?->category?->slug;
            $products = $serviceCat === '' ? collect() : $this->base()->whereHas('category', fn ($c) => $c->where('slug', 'like', $serviceCat.'%'))->limit($limit)->get();

            return [$staff, $products];
        }

        return [$staff, $this->base()->whereIn('products.id', $productIds)->limit($limit)->get()];
    }

    /** Available stock per branch for each variant: [variant_id => [branch_id => qty]]. */
    public function availability(Product $product): array
    {
        $rows = DB::table('branch_stock')->whereIn('product_variant_id', $product->variants->pluck('id'))
            ->get(['product_variant_id', 'branch_id', 'qty', 'reserved']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->product_variant_id][$r->branch_id] = max(0, $r->qty - $r->reserved);
        }

        return $out;
    }

    public static function minPrice(Product $product): int
    {
        return (int) ($product->variants->map(fn ($v) => $v->effectivePrice())->min() ?? 0);
    }

    public static function image(Product $product, int $index = 0): ?string
    {
        $img = $product->images[$index] ?? null;
        if (! $img) {
            return null;
        }

        return str_starts_with($img, 'http') || str_starts_with($img, '/') ? $img : asset('storage/'.$img);
    }
}
