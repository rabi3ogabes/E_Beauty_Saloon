<?php

namespace App\Domain\Shop\Actions;

use App\Domain\CRM\Actions\SendClientOtp;
use App\Domain\CRM\Models\Client;
use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Payments\Actions\CreateOnlinePayment;
use App\Domain\Payments\Actions\GiftCardLedger;
use App\Domain\Payments\Actions\WalletLedger;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Events\OrderPlaced;
use App\Domain\Shop\Events\OrderStatusChanged;
use App\Domain\Shop\Models\Address;
use App\Domain\Shop\Models\Cart;
use App\Domain\Shop\Models\Order;
use App\Domain\Shop\Models\ShopCoupon;
use App\Domain\Shop\Support\ShopSettings;
use App\Support\Exceptions\DomainException;
use App\Support\Tenancy\BranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Places a shop order from a cart.
 *
 * $data: fulfilment, branch_id (pickup), zone_id/lat/lng/area + address fields or address_id (delivery),
 *        save_address, delivery_slot (same-day), coupon_code, gift_wrap, gift_message, notes,
 *        customer_name, customer_phone, customer_email,
 *        payment_method (online|cod|wallet|gift_card), cod_method (cash|card_terminal), gateway_id,
 *        use_wallet (bool), gift_card_code.
 *
 * Wallet / gift card are applied first (partial); the remainder is paid online or cash on delivery.
 * Stock is reserved at the fulfilment branch; it's deducted when the order is confirmed:
 *   - fully prepaid by wallet / gift card → confirmed immediately
 *   - online → confirmed by the payment webhook / return page (Order::onPaymentSucceeded)
 *   - COD → confirmed after the client's OTP (if required) or by staff.
 */
class PlaceOrder
{
    public function __construct(
        protected QuoteCheckout $quote,
        protected StockLedger $stock,
        protected WalletLedger $wallet,
        protected GiftCardLedger $giftCards,
        protected CreateOnlinePayment $onlinePayment,
        protected ConfirmOrder $confirm,
    ) {}

    public function handle(Cart $cart, array $data, ?Client $client = null): Order
    {
        $cart->loadMissing('items');
        $method = $data['payment_method'] ?? 'cod';
        if (! in_array($method, ['online', 'cod', 'wallet', 'gift_card'], true)) {
            throw new DomainException(__('shop.errors.payment_method'));
        }
        $phone = Client::normalizePhone($client?->phone ?? ($data['customer_phone'] ?? ''));
        if (! preg_match('/^\+\d{8,15}$/', $phone)) {
            throw new DomainException(__('shop.errors.phone_required'));
        }

        $address = null;
        if (! empty($data['address_id']) && $client) {
            $address = Address::where('client_id', $client->id)->find($data['address_id']);
        }
        $addr = $address?->snapshot() ?? array_filter([
            'label' => $data['label'] ?? null, 'recipient_name' => $data['recipient_name'] ?? ($data['customer_name'] ?? $client?->name),
            'phone' => $phone, 'area' => $data['area'] ?? null, 'street' => $data['street'] ?? null, 'building' => $data['building'] ?? null,
            'unit' => $data['unit'] ?? null, 'lat' => isset($data['lat']) && $data['lat'] !== '' ? (float) $data['lat'] : null,
            'lng' => isset($data['lng']) && $data['lng'] !== '' ? (float) $data['lng'] : null, 'notes' => $data['address_notes'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $quote = $this->quote->handle([
            'items' => $cart->items->map(fn ($i) => ['variant_id' => $i->product_variant_id, 'qty' => $i->qty])->all(),
            'fulfilment' => $data['fulfilment'] ?? 'delivery',
            'branch_id' => $data['branch_id'] ?? null,
            'zone_id' => $data['zone_id'] ?? $address?->delivery_zone_id,
            'lat' => $addr['lat'] ?? null, 'lng' => $addr['lng'] ?? null, 'area' => $addr['area'] ?? null,
            'delivery_slot' => $data['delivery_slot'] ?? null,
            'coupon_code' => $data['coupon_code'] ?? $cart->coupon_code,
            'gift_wrap' => $data['gift_wrap'] ?? $cart->gift_wrap,
            'client_id' => $client?->id, 'phone' => $phone,
            'strict' => true,
        ]);

        if ($quote->fulfilment !== 'pickup' && empty($addr['area']) && empty($addr['lat']) && empty($addr['street'])) {
            throw new DomainException(__('shop.errors.address_required'));
        }

        $order = DB::transaction(function () use ($cart, $data, $client, $method, $phone, $address, $addr, $quote) {
            $settings = ShopSettings::all();
            $useWallet = ! empty($data['use_wallet']) && $client && $client->wallet_balance > 0;
            $walletAmount = $useWallet ? min((int) $client->fresh()->wallet_balance, $quote->total) : 0;
            $card = ! empty($data['gift_card_code']) ? $this->giftCards->findUsable($data['gift_card_code']) : null;
            $cardAmount = $card ? min((int) $card->balance, $quote->total - $walletAmount) : 0;
            $remainder = $quote->total - $walletAmount - $cardAmount;

            if ($remainder > 0 && in_array($method, ['wallet', 'gift_card'], true)) {
                throw new DomainException(__('shop.errors.remainder_method', ['amount' => money($remainder)]));
            }
            if ($remainder === 0) {
                $method = $cardAmount > 0 && $walletAmount === 0 ? 'gift_card' : ($walletAmount > 0 ? 'wallet' : $method);
            }

            $gateway = null;
            if ($remainder > 0 && $method === 'online') {
                $gateways = TenantPaymentGateway::for('shop', $quote->branch->id)->filter->isOnline();
                $gateway = (! empty($data['gateway_id']) ? $gateways->firstWhere('id', (int) $data['gateway_id']) : null) ?? $gateways->first()
                    ?? throw new DomainException(__('shop.errors.no_gateway'));
            }
            if ($remainder > 0 && $method === 'cod') {
                $this->assertCodAllowed($settings, $remainder, $client, $phone, $quote->branch->id);
            }

            $sequence = (int) Order::withoutGlobalScopes()->where('tenant_id', tenant()->id)->lockForUpdate()->max('sequence') + 1;
            $sequence = max($sequence, 10001);

            $order = Order::create([
                'branch_id' => $quote->branch->id,
                'client_id' => $client?->id,
                'cart_id' => $cart->id,
                'sequence' => $sequence,
                'number' => 'SO-'.$sequence,
                'public_token' => Str::random(32),
                'status' => OrderStatus::Pending,
                'fulfilment' => $quote->fulfilment,
                'delivery_zone_id' => $quote->zone?->id,
                'address_id' => $address?->id,
                'address' => $quote->fulfilment === 'pickup' ? null : $addr,
                'customer_name' => trim((string) ($data['customer_name'] ?? $client?->name ?? __('account.guest'))),
                'customer_phone' => $phone,
                'customer_email' => $data['customer_email'] ?? $client?->email,
                'delivery_slot' => $quote->fulfilment === 'same_day' ? ($data['delivery_slot'] ?? null) : null,
                'ready_estimate_at' => $quote->readyEstimate,
                'gift_wrap' => $quote->giftWrapFee > 0,
                'gift_message' => ($data['gift_message'] ?? $cart->gift_message) ? mb_substr((string) ($data['gift_message'] ?? $cart->gift_message), 0, 300) : null,
                'shop_coupon_id' => $quote->coupon?->id,
                'coupon_code' => $quote->coupon?->code,
                'subtotal' => $quote->subtotal,
                'discount' => $quote->discount,
                'delivery_fee' => $quote->deliveryFee,
                'gift_wrap_fee' => $quote->giftWrapFee,
                'tax' => $quote->tax,
                'tax_inclusive' => $quote->taxInclusive,
                'total' => $quote->total,
                'paid' => 0,
                'wallet_applied' => $walletAmount,
                'gift_card_applied' => $cardAmount,
                'gift_card_id' => $card?->id,
                'payment_method' => $method,
                'cod_method' => $method === 'cod' ? (in_array($data['cod_method'] ?? null, ['cash', 'card_terminal'], true) ? $data['cod_method'] : 'cash') : null,
                'cod_otp_required' => $method === 'cod' && $remainder > 0 && (bool) $settings['cod_otp_required'],
                'notes' => isset($data['notes']) ? mb_substr((string) $data['notes'], 0, 1000) : null,
                'locale' => $client?->locale ?? app()->getLocale(),
                'currency' => tenant()->currency ?? 'QAR',
            ]);

            foreach ($quote->lines as $line) {
                $v = $line['variant'];
                $item = $order->items()->create([
                    'product_id' => $v->product_id,
                    'product_variant_id' => $v->id,
                    'name' => $v->product->getTranslations('name'),
                    'variant_name' => $v->getTranslation('name', app()->getLocale(), false) ?: null,
                    'sku' => $v->sku,
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'total' => $line['total'],
                ]);
                if ($v->product->track_stock) {
                    $this->stock->reserve($order->branch_id, $v->id, $item->qty);
                }
            }
            $order->forceFill(['stock_state' => 'reserved'])->save();

            if ($quote->coupon) {
                $locked = ShopCoupon::lockForUpdate()->find($quote->coupon->id);
                if ($locked->max_uses !== null && $locked->uses_count >= $locked->max_uses) {
                    throw new DomainException(__('shop.errors.coupon_used_up'));
                }
                $locked->increment('uses_count');
            }

            if ($walletAmount > 0) {
                $this->wallet->debit($client, $walletAmount, 'spend', $order, "order:{$order->id}:wallet");
                $this->recordPayment($order, 'wallet', $walletAmount);
            }
            if ($cardAmount > 0) {
                $this->giftCards->redeem($card, $cardAmount, $order, "order:{$order->id}:gift_card");
                $this->recordPayment($order, 'gift_card', $cardAmount, ['gift_card_id' => $card->id]);
            }
            $order->forceFill(['paid' => $walletAmount + $cardAmount])->save();

            if ($client && ! empty($data['save_address']) && ! $address && $quote->fulfilment !== 'pickup') {
                Address::create(array_merge($addr, ['client_id' => $client->id, 'delivery_zone_id' => $quote->zone?->id, 'label' => $addr['label'] ?? __('shop.checkout.home'),
                    'is_default' => ! Address::where('client_id', $client->id)->exists()]));
            }

            $order->history()->create(['from_status' => null, 'to_status' => OrderStatus::Pending->value, 'user_id' => auth('web')->id()]);
            activity()->performedOn($order)->withProperties(['total' => $order->total, 'method' => $method, 'fulfilment' => $order->fulfilment])->log('order.placed');

            $cart->update(['status' => 'converted', 'order_id' => $order->id, 'phone' => $phone]);

            if ($remainder === 0) {
                return $this->confirm->handle($order, 'prepaid');
            }

            if ($gateway) {
                // Hosted page → our order hop → /payments/return/{key} (status re-check) → meta.return_to (tracking page).
                $returnUrl = tenant()->url('/shop/order/'.$order->public_token.'/return');
                $payment = $this->onlinePayment->handle($order, $gateway, $remainder, $returnUrl,
                    ['name' => $order->customer_name, 'phone' => $phone, 'email' => $order->customer_email], 'payment', $order->branch_id, $client?->id);
                $payment->update(['meta' => array_merge($payment->meta ?? [], ['return_to' => $order->trackingUrl()])]);
            }

            $fresh = Order::withoutGlobalScope(BranchScope::class)->findOrFail($order->id);
            DB::afterCommit(fn () => OrderStatusChanged::dispatch($fresh, null, OrderStatus::Pending));

            return $fresh;
        });

        if ($order->cod_otp_required && ! $order->cod_otp_verified_at) {
            app(SendClientOtp::class)->handle($order->customer_phone);
        }
        OrderPlaced::dispatch($order);

        return $order;
    }

    protected function recordPayment(Order $order, string $method, int $amount, array $meta = []): Payment
    {
        return Payment::create([
            'branch_id' => $order->branch_id, 'client_id' => $order->client_id,
            'payable_type' => $order->getMorphClass(), 'payable_id' => $order->id,
            'method' => $method, 'driver' => $method, 'purpose' => 'payment', 'status' => 'succeeded',
            'amount' => $amount, 'currency' => $order->currency, 'paid_at' => now(),
            'idempotency_key' => "order:{$order->id}:{$method}", 'meta' => $meta,
        ]);
    }

    /** COD must be enabled, offered by the branch, and within the per-client outstanding COD limit. */
    protected function assertCodAllowed(array $settings, int $amount, ?Client $client, string $phone, int $branchId): void
    {
        $offered = TenantPaymentGateway::where('driver', 'cod')->exists()
            ? TenantPaymentGateway::for('shop', $branchId)->contains(fn ($g) => $g->driver === 'cod')
            : true;
        if (! $settings['cod_enabled'] || ! $offered) {
            throw new DomainException(__('shop.errors.cod_disabled'));
        }
        if ($client?->is_blocked) {
            throw new DomainException(__('shop.errors.cod_disabled'));
        }
        $limit = (int) $settings['cod_limit'];
        if ($limit > 0) {
            $outstanding = (int) Order::withoutGlobalScope(BranchScope::class)->where('payment_method', 'cod')->whereIn('status', OrderStatus::open())
                ->where(fn ($q) => $q->where('customer_phone', $phone)->when($client, fn ($w) => $w->orWhere('client_id', $client->id)))
                ->get(['total', 'paid'])->sum(fn ($o) => max(0, $o->total - $o->paid));
            if ($outstanding + $amount > $limit) {
                throw new DomainException(__('shop.errors.cod_limit', ['limit' => money($limit)]));
            }
        }
    }
}
