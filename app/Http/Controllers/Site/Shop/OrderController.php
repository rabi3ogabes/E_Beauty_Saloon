<?php

namespace App\Http\Controllers\Site\Shop;

use App\Domain\CRM\Actions\SendClientOtp;
use App\Domain\Shop\Actions\VerifyCodOtp;
use App\Domain\Shop\Models\Order;
use App\Domain\Shop\Support\CartManager;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use App\Support\Tenancy\BranchScope;
use Illuminate\Http\Request;

/** Order confirmation / tracking page (unguessable token link, sent in every update). */
class OrderController extends Controller
{
    public function show(string $token)
    {
        $order = $this->find($token);
        $order->load(['items', 'history', 'driver', 'zone', 'branch' => fn ($q) => $q->withoutGlobalScopes()]);
        $pendingPayment = $order->payments()->where('status', 'pending')->latest('id')->first();
        $failedPayment = $order->payments()->where('status', 'failed')->latest('id')->first();

        return view('site.shop.order', compact('order', 'pendingPayment', 'failedPayment'));
    }

    /** Gateway return URL: hand over to the shared payment return page, which re-checks the status. */
    public function paymentReturn(string $token)
    {
        $order = $this->find($token);
        $payment = $order->payments()->where('method', 'online')->latest('id')->first();

        return $payment ? redirect()->route('payments.return', $payment->idempotency_key) : redirect()->route('shop.orders.show', $token);
    }

    public function verifyOtp(Request $request, string $token, VerifyCodOtp $verify)
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        try {
            $verify->handle($this->find($token), $data['code']);
        } catch (DomainException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('status', __('shop.order.otp_confirmed'));
    }

    public function resendOtp(string $token, SendClientOtp $send)
    {
        $order = $this->find($token);
        abort_unless($order->cod_otp_required && ! $order->cod_otp_verified_at, 404);
        $send->handle($order->customer_phone);

        return back()->with('status', __('account.code_sent'));
    }

    public function reorder(Request $request, string $number, CartManager $carts)
    {
        $client = $request->user('client');
        $order = Order::withoutGlobalScope(BranchScope::class)->where('client_id', $client->id)->where('number', $number)->with('items')->firstOrFail();
        $cart = $carts->current($request, create: true);
        $skipped = 0;
        foreach ($order->items as $item) {
            try {
                $item->product_variant_id ? $carts->add($cart, $item->product_variant_id, $item->qty) : $skipped++;
            } catch (DomainException) {
                $skipped++;
            }
        }

        return redirect()->route('shop.cart')->with('status', $skipped ? __('shop.account.reorder_partial', ['count' => $skipped]) : __('shop.account.reorder_done'));
    }

    protected function find(string $token): Order
    {
        return Order::withoutGlobalScope(BranchScope::class)->where('public_token', $token)->firstOrFail();
    }
}
