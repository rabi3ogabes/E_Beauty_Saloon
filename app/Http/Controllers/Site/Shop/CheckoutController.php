<?php

namespace App\Http\Controllers\Site\Shop;

use App\Domain\CRM\Actions\SendClientOtp;
use App\Domain\CRM\Actions\VerifyClientOtp;
use App\Domain\CRM\Models\Client;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Domain\Shop\Actions\PlaceOrder;
use App\Domain\Shop\Actions\QuoteCheckout;
use App\Domain\Shop\Models\Address;
use App\Domain\Shop\Support\CartManager;
use App\Domain\Shop\Support\FulfilmentPlanner;
use App\Domain\Shop\Support\ShopSettings;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One-page checkout. Guests verify their phone with an inline OTP (which signs them in as a client),
 * then choose delivery / pickup / same-day and a payment method.
 */
class CheckoutController extends Controller
{
    public function __construct(protected CartManager $carts, protected FulfilmentPlanner $planner) {}

    public function show(Request $request)
    {
        $cart = $this->carts->current($request);
        if (! $cart || $cart->items->isEmpty()) {
            return redirect()->route('shop.index')->with('status', __('shop.errors.cart_empty'));
        }
        $client = $this->client();
        $zones = $this->planner->zones();
        $gateways = TenantPaymentGateway::for('shop')->filter->isOnline()->values();

        return view('site.shop.checkout', [
            'cart' => $this->carts->payload($cart),
            'client' => $client,
            'addresses' => $client ? Address::where('client_id', $client->id)->orderByDesc('is_default')->get() : collect(),
            'zones' => $zones,
            'sameDayZones' => $zones->filter(fn ($z) => $this->planner->sameDayOpen($z))->pluck('id')->values(),
            'pickupBranches' => $this->planner->pickupBranches(),
            'gateways' => $gateways,
            'settings' => ShopSettings::all(),
        ]);
    }

    public function quote(Request $request, QuoteCheckout $quote)
    {
        $cart = $this->carts->current($request) ?? abort(404);
        $client = $this->client();
        $address = $client && $request->filled('address_id') ? Address::where('client_id', $client->id)->find($request->integer('address_id')) : null;

        try {
            $result = $quote->handle([
                'items' => $cart->items->map(fn ($i) => ['variant_id' => $i->product_variant_id, 'qty' => $i->qty])->all(),
                'fulfilment' => $request->input('fulfilment', 'delivery'),
                'branch_id' => $request->input('branch_id'),
                'zone_id' => $request->input('zone_id') ?: $address?->delivery_zone_id,
                'lat' => $request->input('lat', $address?->lat), 'lng' => $request->input('lng', $address?->lng),
                'area' => $request->input('area', $address?->area),
                'delivery_slot' => $request->input('delivery_slot'),
                'coupon_code' => $cart->coupon_code,
                'gift_wrap' => $cart->gift_wrap,
                'client_id' => $client?->id,
                'phone' => $client?->phone,
            ]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result->toArray());
    }

    public function sendOtp(Request $request, SendClientOtp $send)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $otp = $send->handle($data['phone']);
        $cart = $this->carts->current($request);
        $cart?->update(['phone' => $otp->phone]); // known phone → eligible for abandoned-cart recovery

        return response()->json(['phone' => $otp->phone, 'message' => __('account.code_sent')]);
    }

    public function verifyOtp(Request $request, VerifyClientOtp $verify)
    {
        $data = $request->validate(['phone' => ['required', 'string'], 'code' => ['required', 'digits:6'], 'name' => ['nullable', 'string', 'max:100']]);
        $guestCart = $this->carts->current($request);
        $client = $verify->handle($data['phone'], $data['code'], $data['name'] ?? null, 'shop');
        Auth::guard('client')->login($client, remember: true);
        $request->session()->regenerate();
        if ($guestCart) {
            $this->carts->remember($request, $guestCart);
        }
        $this->carts->current($request); // merges the guest cart into the client's cart

        return response()->json(['ok' => true, 'csrf' => csrf_token(), 'client' => ['name' => $client->name, 'phone' => $client->phone, 'email' => $client->email, 'wallet' => $client->wallet_balance, 'wallet_formatted' => money($client->wallet_balance)]]);
    }

    public function place(Request $request, PlaceOrder $place)
    {
        $client = $this->client();
        if (! $client) {
            return $this->fail($request, __('shop.errors.verify_phone'));
        }
        $data = $request->validate([
            'fulfilment' => ['required', 'in:delivery,pickup,same_day'],
            'branch_id' => ['nullable', 'integer'],
            'address_id' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer'],
            'area' => ['nullable', 'string', 'max:120'],
            'street' => ['nullable', 'string', 'max:160'],
            'building' => ['nullable', 'string', 'max:60'],
            'unit' => ['nullable', 'string', 'max:40'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'address_notes' => ['nullable', 'string', 'max:255'],
            'save_address' => ['nullable', 'boolean'],
            'delivery_slot' => ['nullable', 'string', 'max:40'],
            'customer_name' => ['required', 'string', 'max:100'],
            'customer_email' => ['nullable', 'email', 'max:150'],
            'payment_method' => ['required', 'in:online,cod,wallet,gift_card'],
            'cod_method' => ['nullable', 'in:cash,card_terminal'],
            'gateway_id' => ['nullable', 'integer'],
            'use_wallet' => ['nullable', 'boolean'],
            'gift_card_code' => ['nullable', 'string', 'max:24'],
            'gift_message' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $cart = $this->carts->current($request);
        if (! $cart || $cart->items->isEmpty()) {
            return $this->fail($request, __('shop.errors.cart_empty'));
        }

        try {
            if ($client->name === __('account.guest') && $data['customer_name']) {
                $client->update(['name' => $data['customer_name']]);
            }
            $order = $place->handle($cart, $data, $client);
        } catch (DomainException $e) {
            return $this->fail($request, $e->getMessage());
        }

        $checkout = $order->payments()->where('status', 'pending')->latest('id')->value('checkout_url');
        $redirect = $checkout ?: $order->trackingUrl();

        return $request->expectsJson() ? response()->json(['redirect' => $redirect, 'order' => $order->number]) : redirect()->away($redirect);
    }

    protected function client(): ?Client
    {
        $client = Auth::guard('client')->user();

        return $client instanceof Client && $client->tenant_id === tenant()?->id ? $client : null;
    }

    protected function fail(Request $request, string $message)
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], 422)
            : back()->withInput()->withErrors(['checkout' => $message]);
    }
}
