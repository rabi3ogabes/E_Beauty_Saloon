<?php

namespace App\Domain\Shop\Support;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\CRM\Models\Client;
use App\Domain\Inventory\Models\BranchStock;
use App\Domain\Shop\Models\Cart;
use App\Domain\Shop\Models\CartItem;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves the visitor's cart (client account, X-Cart-Token header, cookie or session) and mutates it.
 * Guest carts are merged into the client's cart on sign-in.
 */
class CartManager
{
    public const COOKIE = 'shop_cart';

    public function __construct(protected ProductCatalog $catalog) {}

    public function current(?Request $request = null, bool $create = false): ?Cart
    {
        $request ??= request();
        $client = Auth::guard('client')->user();
        $client = $client instanceof Client && $client->tenant_id === tenant()?->id ? $client : null;
        $token = $request->header('X-Cart-Token') ?: $request->cookie(self::COOKIE) ?: ($request->hasSession() ? $request->session()->get(self::COOKIE) : null);

        $guest = $token ? Cart::where('token', $token)->where('status', 'active')->first() : null;
        $cart = $client ? Cart::where('client_id', $client->id)->where('status', 'active')->latest('id')->first() : $guest;

        if ($client && $guest && (! $cart || $guest->id !== $cart->id)) {
            if ($guest->client_id === null || $guest->client_id === $client->id) {
                $cart = $cart ? $this->merge($guest, $cart) : tap($guest)->update(['client_id' => $client->id]);
            }
        }

        if (! $cart && $create) {
            $cart = Cart::create(['token' => Str::random(40), 'client_id' => $client?->id, 'last_activity_at' => now()]);
        }

        if ($cart) {
            $this->remember($request, $cart);
        }

        return $cart?->loadMissing(['items.variant.product.brand', 'client']);
    }

    public function remember(Request $request, Cart $cart): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::COOKIE, $cart->token);
        }
        Cookie::queue(self::COOKIE, $cart->token, 60 * 24 * 30);
    }

    public function merge(Cart $from, Cart $into): Cart
    {
        DB::transaction(function () use ($from, $into) {
            foreach ($from->items as $item) {
                $existing = $into->items()->where('product_variant_id', $item->product_variant_id)->first();
                $existing ? $existing->update(['qty' => max($existing->qty, $item->qty)]) : $into->items()->create($item->only(['product_variant_id', 'qty']));
            }
            $into->update(['coupon_code' => $into->coupon_code ?? $from->coupon_code, 'gift_wrap' => $into->gift_wrap || $from->gift_wrap, 'gift_message' => $into->gift_message ?? $from->gift_message]);
            $from->items()->delete();
            $from->delete();
        });

        return $into->fresh();
    }

    public function add(Cart $cart, int $variantId, int $qty = 1): CartItem
    {
        $variant = $this->sellableVariant($variantId);
        $existing = $cart->items()->where('product_variant_id', $variant->id)->first();
        $newQty = ($existing?->qty ?? 0) + max(1, $qty);
        $this->assertAvailable($variant, $newQty, $cart->branch_id);

        $item = $existing ? tap($existing)->update(['qty' => $newQty]) : $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => $newQty]);
        $cart->touchActivity();
        $cart->unsetRelation('items');

        return $item;
    }

    public function update(Cart $cart, int $itemId, int $qty): void
    {
        $item = $cart->items()->findOrFail($itemId);
        if ($qty <= 0) {
            $item->delete();
        } else {
            $this->assertAvailable($this->sellableVariant($item->product_variant_id), $qty, $cart->branch_id);
            $item->update(['qty' => min($qty, 99)]);
        }
        $cart->touchActivity();
        $cart->unsetRelation('items');
    }

    public function sellableVariant(int $variantId): ProductVariant
    {
        $variant = ProductVariant::with('product')->find($variantId);
        if (! $variant || ! $variant->product || ! $variant->product->is_active || ! $variant->product->sold_online || $variant->product->usage === 'professional') {
            throw new DomainException(__('shop.errors.not_available'));
        }

        return $variant;
    }

    public function assertAvailable(ProductVariant $variant, int $qty, ?int $branchId = null): void
    {
        if (! $variant->product->track_stock) {
            return;
        }
        $q = BranchStock::withoutGlobalScopes()->where('product_variant_id', $variant->id);
        $available = $branchId
            ? (clone $q)->where('branch_id', $branchId)->get()->sum(fn ($s) => $s->available())
            : $q->get()->max(fn ($s) => $s->available()) ?? 0;
        if ($available < $qty) {
            throw new DomainException(__('shop.errors.only_left', ['count' => max(0, (int) $available), 'item' => $variant->displayName()]));
        }
    }

    public function subtotal(Cart $cart): int
    {
        return (int) $cart->items->sum(fn ($i) => $i->lineTotal());
    }

    /** JSON for the slide-over cart. */
    public function payload(?Cart $cart): array
    {
        $items = $cart?->items ?? collect();
        $subtotal = $cart ? $this->subtotal($cart) : 0;
        $coupon = null;
        $discount = 0;
        if ($cart?->coupon_code) {
            try {
                $c = app(CouponValidator::class)->validate($cart->coupon_code, $subtotal, $cart->client_id, $cart->contactPhone());
                $coupon = ['code' => $c->code, 'free_shipping' => $c->free_shipping];
                $discount = $c->discountFor($subtotal);
            } catch (DomainException $e) {
                $coupon = ['code' => $cart->coupon_code, 'error' => $e->getMessage()];
            }
        }
        $wrap = $cart?->gift_wrap ? (int) ShopSettings::get('gift_wrap_price') : 0;
        $products = $items->map(fn ($i) => $i->variant?->product)->filter()->unique('id')->values();

        return [
            'token' => $cart?->token,
            'count' => (int) $items->sum('qty'),
            'items' => $items->filter(fn ($i) => $i->variant)->map(fn (CartItem $i) => [
                'id' => $i->id,
                'variant_id' => $i->product_variant_id,
                'name' => $i->variant->product->name,
                'variant' => $i->variant->getTranslation('name', app()->getLocale(), false) ?: null,
                'brand' => $i->variant->product->brand?->name,
                'swatch' => $i->variant->swatch,
                'image' => ProductCatalog::image($i->variant->product),
                'url' => route('shop.product', $i->variant->product->slug),
                'qty' => $i->qty,
                'unit_price' => $i->variant->effectivePrice(),
                'line_total' => $i->lineTotal(),
                'line_total_formatted' => money($i->lineTotal()),
            ])->values()->all(),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'gift_wrap' => (bool) $cart?->gift_wrap,
            'gift_wrap_fee' => $wrap,
            'gift_message' => $cart?->gift_message,
            'coupon' => $coupon,
            'total' => max(0, $subtotal - $discount) + $wrap,
            'formatted' => [
                'subtotal' => money($subtotal), 'discount' => money($discount), 'gift_wrap' => money($wrap), 'total' => money(max(0, $subtotal - $discount) + $wrap),
            ],
            'upsell' => $this->catalog->routineFor($products)->map(fn ($p) => [
                'id' => $p->id, 'variant_id' => $p->variants->sortByDesc('is_default')->first()?->id, 'name' => $p->name, 'brand' => $p->brand?->name,
                'price' => money(ProductCatalog::minPrice($p)), 'image' => ProductCatalog::image($p), 'url' => route('shop.product', $p->slug),
            ])->values()->all(),
        ];
    }
}
