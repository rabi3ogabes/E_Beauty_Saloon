<?php

namespace App\Http\Controllers\Site\Shop;

use App\Domain\Shop\Models\Cart;
use App\Domain\Shop\Support\CartManager;
use App\Domain\Shop\Support\CouponValidator;
use App\Domain\Shop\Support\ShopSettings;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\Request;

/** Slide-over cart API (JSON) + a full-page cart for no-JS / deep links. */
class CartController extends Controller
{
    public function __construct(protected CartManager $carts) {}

    public function show(Request $request)
    {
        $payload = $this->carts->payload($this->carts->current($request));

        return $request->expectsJson() ? response()->json($payload) : view('site.shop.cart', ['cart' => $payload]);
    }

    public function add(Request $request)
    {
        $data = $request->validate(['variant_id' => ['required', 'integer'], 'qty' => ['nullable', 'integer', 'min:1', 'max:20']]);

        return $this->respond($request, function () use ($request, $data) {
            $cart = $this->carts->current($request, create: true);
            $this->carts->add($cart, (int) $data['variant_id'], (int) ($data['qty'] ?? 1));

            return [$cart, __('shop.cart.added')];
        });
    }

    public function update(Request $request, int $item)
    {
        $data = $request->validate(['qty' => ['required', 'integer', 'min:0', 'max:99']]);

        return $this->respond($request, function () use ($request, $item, $data) {
            $cart = $this->carts->current($request) ?? abort(404);
            $this->carts->update($cart, $item, (int) $data['qty']);

            return [$cart, null];
        });
    }

    public function remove(Request $request, int $item)
    {
        return $this->respond($request, function () use ($request, $item) {
            $cart = $this->carts->current($request) ?? abort(404);
            $this->carts->update($cart, $item, 0);

            return [$cart, null];
        });
    }

    public function coupon(Request $request, CouponValidator $validator)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);

        return $this->respond($request, function () use ($request, $data, $validator) {
            $cart = $this->carts->current($request) ?? throw new DomainException(__('shop.errors.cart_empty'));
            $coupon = $validator->validate($data['code'], $this->carts->subtotal($cart), $cart->client_id, $cart->contactPhone());
            $cart->update(['coupon_code' => $coupon->code]);

            return [$cart, __('shop.cart.coupon_applied')];
        });
    }

    public function removeCoupon(Request $request)
    {
        return $this->respond($request, function () use ($request) {
            $cart = $this->carts->current($request) ?? abort(404);
            $cart->update(['coupon_code' => null]);

            return [$cart, null];
        });
    }

    public function gift(Request $request)
    {
        $data = $request->validate(['gift_wrap' => ['boolean'], 'gift_message' => ['nullable', 'string', 'max:300']]);

        return $this->respond($request, function () use ($request, $data) {
            $cart = $this->carts->current($request, create: true);
            $cart->update([
                'gift_wrap' => ShopSettings::get('gift_wrap_enabled') && ($data['gift_wrap'] ?? false),
                'gift_message' => $data['gift_message'] ?? null,
            ]);

            return [$cart, null];
        });
    }

    /** Link from abandoned-cart messages: restores the cart in this browser. */
    public function recover(Request $request, string $token)
    {
        $cart = Cart::where('token', $token)->where('status', 'active')->first();
        if ($cart) {
            $this->carts->remember($request, $cart);
        }

        return redirect()->route('shop.cart');
    }

    protected function respond(Request $request, \Closure $callback)
    {
        try {
            [$cart, $message] = $callback();
        } catch (DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'cart' => $this->carts->payload($this->carts->current($request))], 422);
            }

            return back()->withErrors(['cart' => $e->getMessage()]);
        }
        $payload = $this->carts->payload($cart->fresh(['items.variant.product.brand', 'client']));

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'cart' => $payload])
            : back()->with('status', $message);
    }
}
