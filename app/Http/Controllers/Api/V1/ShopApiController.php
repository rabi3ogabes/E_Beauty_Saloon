<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\CRM\Models\Client;
use App\Domain\Shop\Models\Order;
use App\Domain\Shop\Support\CartManager;
use App\Domain\Shop\Support\FulfilmentPlanner;
use App\Domain\Shop\Support\ProductCatalog;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\BranchScope;
use Illuminate\Http\Request;

/**
 * Mobile app shop API (/api/v1/shop/...). Cart is identified by the X-Cart-Token header
 * (returned by every cart response); client endpoints use Sanctum tokens.
 */
class ShopApiController extends Controller
{
    public function __construct(protected ProductCatalog $catalog, protected CartManager $carts) {}

    public function categories()
    {
        return ['data' => ProductCategory::orderBy('sort')->get()->map(fn ($c) => ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->name, 'parent_id' => $c->parent_id])];
    }

    public function products(Request $request)
    {
        $page = $this->catalog->search($request->only(['q', 'category', 'brand', 'hair_type', 'skin_type', 'min', 'max', 'sort']))->paginate(min(48, $request->integer('per_page', 24)));

        return [
            'data' => $page->getCollection()->map(fn (Product $p) => $this->productSummary($p)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ];
    }

    public function product(string $slug)
    {
        $product = $this->catalog->base()->where('slug', $slug)->firstOrFail();
        $availability = $this->catalog->availability($product);

        return ['data' => $this->productSummary($product) + [
            'description' => $product->description,
            'video_url' => $product->video_url,
            'attributes' => $product->attributes,
            'images' => collect($product->images ?? [])->keys()->map(fn ($i) => ProductCatalog::image($product, $i))->filter()->values(),
            'variants' => $product->variants->map(fn ($v) => [
                'id' => $v->id, 'name' => $v->getTranslation('name', app()->getLocale(), false), 'sku' => $v->sku, 'swatch' => $v->swatch,
                'price' => $v->effectivePrice(), 'regular_price' => (int) $v->price, 'availability' => $availability[$v->id] ?? (object) [],
            ]),
            'related' => $this->catalog->related($product)->map(fn ($p) => $this->productSummary($p)),
        ]];
    }

    public function zones(FulfilmentPlanner $planner)
    {
        return [
            'zones' => $planner->zones()->map(fn ($z) => ['id' => $z->id, 'name' => $z->name, 'areas' => $z->areas, 'fee' => (int) $z->fee, 'min_order' => (int) $z->min_order,
                'free_shipping_threshold' => $z->free_shipping_threshold, 'same_day' => $planner->sameDayOpen($z), 'same_day_slots' => $planner->sameDaySlots($z)]),
            'pickup_branches' => $planner->pickupBranches()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'ready_at' => $planner->pickupEstimate($b)->toIso8601String()]),
        ];
    }

    public function cart(Request $request)
    {
        return $this->carts->payload($this->carts->current($request));
    }

    public function addToCart(Request $request)
    {
        $data = $request->validate(['variant_id' => ['required', 'integer'], 'qty' => ['nullable', 'integer', 'min:1', 'max:20']]);
        $cart = $this->carts->current($request, create: true);
        $this->carts->add($cart, (int) $data['variant_id'], (int) ($data['qty'] ?? 1));

        return $this->carts->payload($cart->fresh(['items.variant.product.brand', 'client']));
    }

    public function updateCart(Request $request, int $item)
    {
        $data = $request->validate(['qty' => ['required', 'integer', 'min:0', 'max:99']]);
        $cart = $this->carts->current($request) ?? abort(404);
        $this->carts->update($cart, $item, (int) $data['qty']);

        return $this->carts->payload($cart->fresh(['items.variant.product.brand', 'client']));
    }

    public function orders(Request $request)
    {
        $client = $request->user();
        abort_unless($client instanceof Client && $client->tenant_id === tenant()?->id, 403);

        return ['data' => Order::withoutGlobalScope(BranchScope::class)->where('client_id', $client->id)->with('items')->latest('id')->limit(50)->get()->map(fn (Order $o) => [
            'number' => $o->number, 'status' => $o->status->value, 'status_label' => $o->status->label(), 'fulfilment' => $o->fulfilment,
            'total' => (int) $o->total, 'paid' => (int) $o->paid, 'currency' => $o->currency, 'placed_at' => $o->created_at->toIso8601String(),
            'tracking_url' => $o->trackingUrl(),
            'items' => $o->items->map(fn ($i) => ['name' => $i->label(), 'qty' => $i->qty, 'total' => (int) $i->total, 'variant_id' => $i->product_variant_id]),
        ])];
    }

    protected function productSummary(Product $p): array
    {
        return [
            'id' => $p->id, 'slug' => $p->slug, 'name' => $p->name, 'brand' => $p->brand?->name, 'category' => $p->category?->slug,
            'price_from' => ProductCatalog::minPrice($p), 'currency' => tenant()?->currency ?? 'QAR', 'image' => ProductCatalog::image($p),
            'default_variant_id' => $p->variants->sortByDesc('is_default')->first()?->id,
            'url' => route('shop.product', $p->slug),
        ];
    }
}
