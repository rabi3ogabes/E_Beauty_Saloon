<?php

namespace App\Http\Controllers\Site\Shop;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Shop\Models\Wishlist;
use App\Domain\Shop\Support\ProductCatalog;
use App\Domain\Shop\Support\ShopSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StorefrontController extends Controller
{
    public function __construct(protected ProductCatalog $catalog) {}

    public function index(Request $request, ?ProductCategory $category = null, ?Brand $brand = null)
    {
        $filters = $request->only(['q', 'category', 'brand', 'hair_type', 'skin_type', 'min', 'max', 'sort']);
        if ($category) {
            $filters['category'] = $category->slug;
        }
        if ($brand) {
            $filters['brand'] = [$brand->slug];
        }
        $filters['brand'] = array_values(array_filter((array) ($filters['brand'] ?? [])));

        $products = $this->catalog->search($filters)->paginate(24)->withQueryString();

        if ($request->boolean('partial')) {
            return response()->json([
                'html' => view('site.shop.partials.grid', ['products' => $products, 'wishlisted' => $this->wishlistedIds($request)])->render(),
                'total' => $products->total(),
            ]);
        }

        return view('site.shop.index', [
            'products' => $products,
            'filters' => $filters,
            'facets' => $this->catalog->facets(),
            'category' => $category,
            'brand' => $brand,
            'wishlisted' => $this->wishlistedIds($request),
            'sorts' => ProductCatalog::SORTS,
        ]);
    }

    public function category(Request $request, string $slug)
    {
        return $this->index($request, ProductCategory::where('slug', $slug)->firstOrFail());
    }

    public function brand(Request $request, string $slug)
    {
        return $this->index($request, null, Brand::where('slug', $slug)->firstOrFail());
    }

    public function suggest(Request $request)
    {
        $term = mb_substr(trim((string) $request->query('q')), 0, 80);
        if (mb_strlen($term) < 2) {
            return response()->json(['items' => []]);
        }
        $items = $this->catalog->search(['q' => $term])->limit(6)->get()->map(fn (Product $p) => [
            'name' => $p->name,
            'brand' => $p->brand?->name,
            'price' => money(ProductCatalog::minPrice($p)),
            'image' => ProductCatalog::image($p),
            'url' => route('shop.product', $p->slug),
        ]);

        return response()->json(['items' => $items]);
    }

    public function product(Request $request, string $slug)
    {
        $product = $this->catalog->base()->where('slug', $slug)->firstOrFail();
        $product->load(['reviews', 'variants' => fn ($q) => $q->orderByDesc('is_default')->orderBy('id')]);
        $client = Auth::guard('client')->user();
        [$stylist, $stylistPicks] = $this->catalog->stylistPicks($client);

        $branches = Branch::where('status', 'active')->orderBy('sort')->get();
        $availability = $this->catalog->availability($product);

        return view('site.shop.product', [
            'product' => $product,
            'branches' => $branches,
            'availability' => $availability,
            'related' => $this->catalog->related($product),
            'services' => $this->catalog->usedInServices($product),
            'stylist' => $stylist,
            'stylistPicks' => $stylistPicks->reject(fn ($p) => $p->id === $product->id),
            'reviews' => $product->reviews->take(6),
            'rating' => round((float) $product->reviews->avg('rating'), 1),
            'reviewsCount' => $product->reviews->count(),
            'wishlisted' => in_array($product->id, $this->wishlistedIds($request), true),
            'lowStockBadge' => (int) ShopSettings::get('low_stock_badge'),
        ]);
    }

    /** @return list<int> */
    protected function wishlistedIds(Request $request): array
    {
        $client = Auth::guard('client')->user();
        $token = $request->cookie(WishlistController::COOKIE);
        if (! $client && ! $token) {
            return [];
        }

        return Wishlist::query()->when($client, fn ($q) => $q->where('client_id', $client->id), fn ($q) => $q->where('token', $token))
            ->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }
}
