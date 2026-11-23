<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\CRM\Models\Client;
use App\Domain\Shop\Actions\PlaceOrder;
use App\Domain\Shop\Models\Cart;
use App\Domain\Shop\Models\Order;
use App\Domain\Shop\Support\CartManager;
use App\Domain\Shop\Support\FulfilmentPlanner;
use App\Domain\Shop\Support\ProductCatalog;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use App\Support\Money\Money;
use App\Support\Tenancy\BranchScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @group Shop
 *
 * Mobile app shop API (/api/v1/shop/...). Cart is identified by the X-Cart-Token header
 * (returned by every cart response); client endpoints use Sanctum tokens. Orders are placed with the same
 * PlaceOrder action as the website checkout.
 */
class ShopApiController extends Controller
{
    public function __construct(protected ProductCatalog $catalog, protected CartManager $carts) {}

    /** @unauthenticated */
    public function categories()
    {
        return ['data' => ProductCategory::orderBy('sort')->get()->map(fn ($c) => ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->name, 'parent_id' => $c->parent_id])];
    }

    /**
     * Products (search + filters).
     *
     * @unauthenticated
     *
     * @queryParam q string Search (Arabic / English). Example: oil
     * @queryParam category string Category slug.
     * @queryParam sort string
     */
    public function products(Request $request)
    {
        $page = $this->catalog->search($request->only(['q', 'category', 'brand', 'hair_type', 'skin_type', 'min', 'max', 'sort']))->paginate(min(48, $request->integer('per_page', 24)));

        return [
            'data' => $page->getCollection()->map(fn (Product $p) => $this->productSummary($p)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    /**
     * Product detail by slug (or numeric id).
     *
     * @unauthenticated
     */
    public function product(string $slug)
    {
        $product = $this->catalog->base()->where(ctype_digit($slug) ? 'id' : 'slug', $slug)->firstOrFail();
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

    /** @unauthenticated */
    public function zones(FulfilmentPlanner $planner)
    {
        return [
            'zones' => $planner->zones()->map(fn ($z) => ['id' => $z->id, 'name' => $z->name, 'areas' => $z->areas, 'fee' => (int) $z->fee, 'min_order' => (int) $z->min_order,
                'free_shipping_threshold' => $z->free_shipping_threshold, 'same_day' => $planner->sameDayOpen($z), 'same_day_slots' => $planner->sameDaySlots($z)]),
            'pickup_branches' => $planner->pickupBranches()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'ready_at' => $planner->pickupEstimate($b)->toIso8601String()]),
        ];
    }

    /**
     * Cart (by X-Cart-Token).
     *
     * @unauthenticated
     */
    public function cart(Request $request)
    {
        return $this->carts->payload($this->carts->current($request));
    }

    /** @unauthenticated */
    public function addToCart(Request $request)
    {
        $data = $request->validate(['variant_id' => ['required', 'integer'], 'qty' => ['nullable', 'integer', 'min:1', 'max:20']]);
        $cart = $this->carts->current($request, create: true);
        $this->carts->add($cart, (int) $data['variant_id'], (int) ($data['qty'] ?? 1));

        return $this->carts->payload($cart->fresh(['items.variant.product.brand', 'client']));
    }

    /** @unauthenticated */
    public function updateCart(Request $request, int $item)
    {
        $data = $request->validate(['qty' => ['required', 'integer', 'min:0', 'max:99']]);
        $cart = $this->carts->current($request) ?? abort(404);
        $this->carts->update($cart, $item, (int) $data['qty']);

        return $this->carts->payload($cart->fresh(['items.variant.product.brand', 'client']));
    }

    /** My orders (latest 50). */
    public function orders(Request $request)
    {
        $client = $request->user();
        abort_unless($client instanceof Client && $client->tenant_id === tenant()?->id, 403);

        return ['data' => Order::withoutGlobalScope(BranchScope::class)->where('client_id', $client->id)->with('items')->latest('id')->limit(50)->get()
            ->map(fn (Order $o) => $this->orderPayload($o))];
    }

    /** One of my orders by number (SO-10001). */
    public function order(Request $request, string $number)
    {
        $order = Order::withoutGlobalScope(BranchScope::class)->where('client_id', $request->user()->id)->where('number', strtoupper($number))->with('items')->firstOrFail();

        return ['data' => $this->orderPayload($order)];
    }

    /**
     * Place an order.
     *
     * Uses the X-Cart-Token cart, or builds one from `items` (the app keeps its cart locally). With
     * `payment_method=online` the response carries `checkout_url` (hosted payment page). Send an Idempotency-Key.
     *
     * @header Idempotency-Key 0b6f6a52-0f87-4d64-8f5e-2b8c9a0d1e11
     *
     * @bodyParam items object[] `[{variant_id, qty}]` (omit to use the X-Cart-Token cart).
     * @bodyParam fulfilment string required delivery|pickup|same_day. Example: pickup
     * @bodyParam branch_id integer Pickup branch. Example: 1
     * @bodyParam payment_method string online|cod|wallet|gift_card (default cod). Example: online
     * @bodyParam area string Delivery area. Example: West Bay
     */
    public function placeOrder(Request $request, PlaceOrder $place)
    {
        $data = $request->validate([
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.variant_id' => ['required_with:items', 'integer'],
            'items.*.qty' => ['nullable', 'integer', 'min:1', 'max:20'],
            'fulfilment' => ['required', 'in:delivery,pickup,same_day'],
            'branch_id' => ['nullable', 'integer'],
            'address_id' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer'],
            'area' => ['nullable', 'string', 'max:120'],
            'street' => ['nullable', 'string', 'max:160'],
            'building' => ['nullable', 'string', 'max:60'],
            'unit' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:160'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'address_notes' => ['nullable', 'string', 'max:255'],
            'save_address' => ['nullable', 'boolean'],
            'delivery_slot' => ['nullable', 'string', 'max:40'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_email' => ['nullable', 'email', 'max:150'],
            'payment_method' => ['nullable', 'in:online,cod,wallet,gift_card'],
            'cod_method' => ['nullable', 'in:cash,card_terminal'],
            'use_wallet' => ['nullable', 'boolean'],
            'gift_card_code' => ['nullable', 'string', 'max:24'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'gift_message' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $client = $request->user();
        Auth::guard('client')->setUser($client); // CartManager binds / merges carts for the signed-in client

        $cart = ! empty($data['items']) ? $this->cartFromItems($data['items']) : $this->carts->current($request);
        if (! $cart || $cart->items()->doesntExist()) {
            throw new DomainException(__('shop.errors.cart_empty'));
        }
        if (! empty($data['address']) && empty($data['street'])) {
            $data['street'] = $data['address'];
        }
        $data['customer_name'] ??= $client->name;
        $data['payment_method'] ??= 'cod';

        $order = $place->handle($cart->fresh('items'), $data, $client);

        return response()->json(['data' => $this->orderPayload($order->fresh('items'))], 201);
    }

    /** A fresh cart (owned by the client) from the app's local lines; availability is checked by CartManager. */
    protected function cartFromItems(array $items): Cart
    {
        return DB::transaction(function () use ($items) {
            $cart = Cart::create(['token' => Str::random(40), 'client_id' => Auth::guard('client')->id(), 'last_activity_at' => now()]);
            foreach ($items as $line) {
                $this->carts->add($cart, (int) $line['variant_id'], (int) ($line['qty'] ?? 1));
            }

            return $cart;
        });
    }

    protected function orderPayload(Order $o): array
    {
        $checkout = $o->payments()->where('status', 'pending')->whereNotNull('checkout_url')->latest('id')->value('checkout_url');

        return [
            'id' => $o->id, 'number' => $o->number, 'reference' => $o->number,
            'status' => $o->status->value, 'status_label' => $o->status->label(), 'fulfilment' => $o->fulfilment,
            'total' => (int) $o->total, 'paid' => (int) $o->paid, 'currency' => $o->currency,
            'amounts' => [
                'subtotal' => Money::of((int) $o->subtotal, $o->currency), 'discount' => Money::of((int) $o->discount, $o->currency),
                'delivery_fee' => Money::of((int) $o->delivery_fee, $o->currency), 'total' => Money::of((int) $o->total, $o->currency),
                'paid' => Money::of((int) $o->paid, $o->currency),
            ],
            'placed_at' => $o->created_at->toIso8601String(),
            'payment' => ['method' => $o->payment_method, 'checkout_url' => $checkout],
            'checkout_url' => $checkout,
            'tracking_url' => $o->trackingUrl(),
            'items' => $o->items->map(fn ($i) => ['name' => $i->label(), 'qty' => $i->qty, 'total' => (int) $i->total, 'variant_id' => $i->product_variant_id]),
        ];
    }

    protected function productSummary(Product $p): array
    {
        $currency = tenant()?->currency ?? 'QAR';

        return [
            'id' => $p->id, 'slug' => $p->slug, 'name' => $p->name, 'brand' => $p->brand?->name, 'category' => $p->category?->slug,
            'price_from' => ProductCatalog::minPrice($p), 'currency' => $currency, 'image' => ProductCatalog::image($p),
            'price' => Money::of(ProductCatalog::minPrice($p), $currency), 'image_url' => ProductCatalog::image($p),
            'default_variant_id' => $p->variants->sortByDesc('is_default')->first()?->id,
            'url' => route('shop.product', $p->slug),
        ];
    }
}
