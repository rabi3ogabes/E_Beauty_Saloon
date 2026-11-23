<?php

namespace App\Http\Controllers\Site\Shop;

use App\Domain\Catalog\Models\Product;
use App\Domain\Shop\Models\Wishlist;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class WishlistController extends Controller
{
    public const COOKIE = 'shop_wishlist';

    public function index(Request $request)
    {
        $items = $this->query($request)?->with(['product' => fn ($q) => $q->with(['brand', 'variants'])])->latest('id')->get() ?? collect();

        return view('site.shop.wishlist', ['products' => $items->pluck('product')->filter()->unique('id')->values()]);
    }

    public function toggle(Request $request, Product $product)
    {
        $client = Auth::guard('client')->user();
        $token = $request->cookie(self::COOKIE);
        if (! $client && ! $token) {
            $token = Str::random(40);
            Cookie::queue(self::COOKIE, $token, 60 * 24 * 180);
        }
        $existing = Wishlist::where('product_id', $product->id)
            ->when($client, fn ($q) => $q->where('client_id', $client->id), fn ($q) => $q->where('token', $token))->first();

        if ($existing) {
            $existing->delete();
            $on = false;
        } else {
            Wishlist::create(['product_id' => $product->id, 'client_id' => $client?->id, 'token' => $client ? null : $token, 'product_variant_id' => $request->integer('variant_id') ?: null]);
            $on = true;
        }

        return $request->expectsJson()
            ? response()->json(['wishlisted' => $on, 'message' => $on ? __('shop.wishlist.added') : __('shop.wishlist.removed')])
            : back();
    }

    protected function query(Request $request)
    {
        $client = Auth::guard('client')->user();
        $token = $request->cookie(self::COOKIE);
        if (! $client && ! $token) {
            return null;
        }

        return Wishlist::query()->when($client, fn ($q) => $q->where('client_id', $client->id), fn ($q) => $q->where('token', $token));
    }
}
