<?php

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Payments\Actions\SettlePayment;
use App\Domain\Payments\Actions\WalletLedger;
use App\Domain\Payments\Gateways\FakeGateway;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Payments\Models\GiftCard;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shop\Actions\CancelOrder;
use App\Domain\Shop\Actions\ConfirmOrder;
use App\Domain\Shop\Actions\MarkDelivered;
use App\Domain\Shop\Actions\MarkOutForDelivery;
use App\Domain\Shop\Actions\MarkPreparing;
use App\Domain\Shop\Actions\MarkReadyForPickup;
use App\Domain\Shop\Actions\PlaceOrder;
use App\Domain\Shop\Actions\ReturnOrder;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Jobs\ReleaseExpiredReservations;
use App\Domain\Shop\Models\Driver;
use App\Domain\Shop\Models\Order;
use App\Support\Exceptions\DomainException;
use App\Support\Tenancy\TenantContext;
use Tests\Feature\Shop\ShopKit;

beforeEach(function () {
    MessagingManager::fake();
    GatewayManager::fake();
});

it('product sold in shop reduces the same branch stock seen in POS', function () {
    $tenant = createTenant();
    $variant = ShopKit::variant($tenant, stock: 10);
    $client = ShopKit::client($tenant);
    $cart = ShopKit::cart($tenant, $client, [[$variant, 3]]);

    $order = asTenant($tenant, fn () => app(PlaceOrder::class)->handle($cart, ['fulfilment' => 'pickup', 'branch_id' => ShopKit::branch($tenant)->id, 'payment_method' => 'cod'], $client));

    // Reserved, not yet deducted: POS still sees 10 on hand, 7 available.
    expect(ShopKit::available($tenant, $variant))->toBe(['qty' => 10, 'reserved' => 3]);

    asTenant($tenant, fn () => app(ConfirmOrder::class)->handle($order));

    expect(ShopKit::available($tenant, $variant))->toBe(['qty' => 7, 'reserved' => 0]);
    $movement = asTenant($tenant, fn () => StockMovement::where('reference_type', $order->getMorphClass())->where('reference_id', $order->id)->first());
    expect($movement->type)->toBe('order')->and($movement->qty)->toBe(-3)->and($movement->branch_id)->toBe(ShopKit::branch($tenant)->id);
});

it('completes a COD delivery order end to end and records the cash payment', function () {
    $tenant = createTenant();
    $variant = ShopKit::variant($tenant, ['price' => 12000], stock: 5);
    $zone = ShopKit::zone($tenant);
    $client = ShopKit::client($tenant);
    $cart = ShopKit::cart($tenant, $client, [[$variant, 2]]);
    $driver = asTenant($tenant, fn () => Driver::create(['name' => 'Ravi', 'phone' => '+97455559999']));

    $order = asTenant($tenant, fn () => app(PlaceOrder::class)->handle($cart, [
        'fulfilment' => 'delivery', 'zone_id' => $zone->id, 'area' => 'West Bay', 'street' => 'Diplomatic St', 'building' => 'Tower 3',
        'payment_method' => 'cod', 'cod_method' => 'cash',
    ], $client));

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->subtotal)->toBe(24000)
        ->and($order->delivery_fee)->toBe(1500)
        ->and($order->total)->toBe(25500)
        ->and($order->number)->toBe('SO-10001');

    asTenant($tenant, function () use ($order, $driver) {
        app(ConfirmOrder::class)->handle($order);
        app(MarkPreparing::class)->handle($order);
        app(MarkOutForDelivery::class)->handle($order, $driver);
        app(MarkDelivered::class)->handle($order, 'cash');
    });

    $order = asTenant($tenant, fn () => Order::with('history')->find($order->id));
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->paid)->toBe(25500)
        ->and($order->driver_id)->toBe($driver->id)
        ->and($order->history->pluck('to_status')->all())->toBe(['pending', 'confirmed', 'preparing', 'out_for_delivery', 'delivered']);
    $cash = asTenant($tenant, fn () => Payment::where('payable_id', $order->id)->where('method', 'cash')->first());
    expect($cash->amount)->toBe(25500)->and($cash->status)->toBe('succeeded');
    expect(ShopKit::available($tenant, $variant))->toBe(['qty' => 3, 'reserved' => 0]);

    // WhatsApp update on every change (placed + 4 transitions) in the client's language.
    expect(FakeChannel::sentTo('whatsapp', $client->phone))->toHaveCount(5);
});

it('completes a pickup order end to end with a ready-time estimate', function () {
    $tenant = createTenant();
    $variant = ShopKit::variant($tenant, stock: 4);
    $client = ShopKit::client($tenant, attrs: ['locale' => 'ar']);
    $cart = ShopKit::cart($tenant, $client, [[$variant, 1]]);
    $branch = ShopKit::branch($tenant);

    $order = asTenant($tenant, fn () => app(PlaceOrder::class)->handle($cart, ['fulfilment' => 'pickup', 'branch_id' => $branch->id, 'payment_method' => 'cod'], $client));
    expect($order->delivery_fee)->toBe(0)->and($order->ready_estimate_at)->not->toBeNull();

    asTenant($tenant, function () use ($order) {
        app(ConfirmOrder::class)->handle($order);
        app(MarkReadyForPickup::class)->handle($order);
        app(MarkDelivered::class)->handle($order, 'card_terminal');
    });

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Delivered)->and($order->paid)->toBe($order->total);
    $ready = collect(FakeChannel::sentTo('whatsapp', $client->phone))->first(fn ($m) => ($m['message']->meta['status'] ?? null) === 'ready_for_pickup');
    expect($ready['message']->locale)->toBe('ar')->and($ready['message']->body)->toContain('جاهز للاستلام');
});

it('completes a SADAD order end to end with the gateway faked', function () {
    $tenant = createTenant();
    ShopKit::gateway($tenant, 'sadad');
    $variant = ShopKit::variant($tenant, ['price' => 20000], stock: 3);
    $zone = ShopKit::zone($tenant);
    $client = ShopKit::client($tenant);
    $cart = ShopKit::cart($tenant, $client, [[$variant, 2]]);

    $order = asTenant($tenant, fn () => app(PlaceOrder::class)->handle($cart, ['fulfilment' => 'delivery', 'zone_id' => $zone->id, 'area' => 'Dafna', 'payment_method' => 'online'], $client));
    $payment = asTenant($tenant, fn () => Payment::where('payable_id', $order->id)->where('status', 'pending')->first());

    expect($payment->driver)->toBe('sadad')
        ->and($payment->amount)->toBe(40000) // free delivery above threshold
        ->and($payment->meta['return_to'])->toContain('/shop/order/'.$order->public_token)
        ->and(FakeGateway::$checkouts)->toHaveCount(1)
        ->and(FakeGateway::$checkouts[0]['return_url'])->toContain('/shop/order/'.$order->public_token.'/return');
    expect(ShopKit::available($tenant, $variant))->toBe(['qty' => 3, 'reserved' => 2]);

    // Client returns from the hosted page → shared return page re-checks status → settled → order confirmed.
    $this->get(tenantUrl($tenant, 'shop/order/'.$order->public_token.'/return'))->assertRedirectContains('/payments/return/'.$payment->idempotency_key);
    $this->get(tenantUrl($tenant, 'payments/return/'.$payment->idempotency_key))->assertRedirectContains('/shop/order/'.$order->public_token);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Confirmed)->and($order->paid)->toBe(40000);
    expect(ShopKit::available($tenant, $variant))->toBe(['qty' => 1, 'reserved' => 0]);

    asTenant($tenant, function () use ($order) {
        app(MarkOutForDelivery::class)->handle($order);
        app(MarkDelivered::class)->handle($order);
    });
    expect($order->fresh()->status)->toBe(OrderStatus::Delivered);
});

it('releases the reservation when the online payment fails', function () {
    $tenant = createTenant();
    ShopKit::gateway($tenant, 'skipcash');
    $variant = ShopKit::variant($tenant, stock: 5);
    $client = ShopKit::client($tenant);
    $cart = ShopKit::cart($tenant, $client, [[$variant, 2]]);

    $order = asTenant($tenant, fn () => app(PlaceOrder::class)->handle($cart, ['fulfilment' => 'pickup', 'branch_id' => ShopKit::branch($tenant)->id, 'payment_method' => 'online'], $client));
    expect(ShopKit::available($tenant, $variant)['reserved'])->toBe(2);

    $payment = asTenant($tenant, fn () => Payment::where('payable_id', $order->id)->first());
    $this->post(tenantUrl($tenant, 'payments/fake/'.$payment->idempotency_key), ['outcome' => 'fail']);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Cancelled)->and($order->cancel_reason)->toBe('payment_failed');
    expect(ShopKit::available($tenant, $variant))->toBe(['qty' => 5, 'reserved' => 0]);
    expect($cart->fresh()->status)->toBe('active'); // basket restored for a retry
});

it('releases reservations of expired unpaid online orders on schedule', function () {
    $tenant = createTenant();
    ShopKit::gateway($tenant, 'myfatoorah');
    $variant = ShopKit::variant($tenant, stock: 5);
    $client = ShopKit::client($tenant);
    $cart = ShopKit::cart($tenant, $client, [[$variant, 1]]);
    $order = asTenant($tenant, fn () => app(PlaceOrder::class)->handle($cart, ['fulfilment' => 'pickup', 'branch_id' => ShopKit::branch($tenant)->id, 'payment_method' => 'online'], $client));

    $this->travel(31)->minutes();
    (new ReleaseExpiredReservations)->handle(app(TenantContext::class), app(SettlePayment::class), app(CancelOrder::class));

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect(ShopKit::available($tenant, $variant)['reserved'])->toBe(0);
});

it('cancels a confirmed order, returns stock and refunds wallet and gift card partial payments', function () {
    $tenant = createTenant();
    $variant = ShopKit::variant($tenant, ['price' => 10000], stock: 5);
    $client = ShopKit::client($tenant);
    asTenant($tenant, function () use ($client) {
        app(WalletLedger::class)->credit($client, 3000, 'topup');
        GiftCard::create(['code' => 'GIFT-123', 'initial_amount' => 2000, 'balance' => 2000, 'status' => 'active']);
    });
    $cart = ShopKit::cart($tenant, $client, [[$variant, 1]]);

    $order = asTenant($tenant, fn () => app(PlaceOrder::class)->handle($cart, [
        'fulfilment' => 'pickup', 'branch_id' => ShopKit::branch($tenant)->id, 'payment_method' => 'cod', 'use_wallet' => true, 'gift_card_code' => 'GIFT-123',
    ], $client->fresh()));

    expect($order->wallet_applied)->toBe(3000)->and($order->gift_card_applied)->toBe(2000)->and($order->paid)->toBe(5000)->and($order->balanceDue())->toBe(5000);

    asTenant($tenant, fn () => app(ConfirmOrder::class)->handle($order));
    expect(ShopKit::available($tenant, $variant)['qty'])->toBe(4);

    asTenant($tenant, fn () => app(CancelOrder::class)->handle($order, 'client'));
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Cancelled)->and($order->refunded)->toBe(5000);
    expect(ShopKit::available($tenant, $variant)['qty'])->toBe(5);
    expect($client->fresh()->wallet_balance)->toBe(3000);
    expect(asTenant($tenant, fn () => GiftCard::where('code', 'GIFT-123')->value('balance')))->toBe(2000);
});

it('confirms immediately when wallet covers the whole order', function () {
    $tenant = createTenant();
    $variant = ShopKit::variant($tenant, ['price' => 4000], stock: 5);
    $client = ShopKit::client($tenant);
    asTenant($tenant, fn () => app(WalletLedger::class)->credit($client, 10000, 'topup'));
    $cart = ShopKit::cart($tenant, $client, [[$variant, 1]]);

    $order = asTenant($tenant, fn () => app(PlaceOrder::class)->handle($cart, ['fulfilment' => 'pickup', 'branch_id' => ShopKit::branch($tenant)->id, 'payment_method' => 'wallet', 'use_wallet' => true], $client->fresh()));

    expect($order->status)->toBe(OrderStatus::Confirmed)->and($order->payment_method)->toBe('wallet')->and($order->paid)->toBe(4000);
    expect($client->fresh()->wallet_balance)->toBe(6000);
});

it('returns a delivered order as store credit and puts stock back', function () {
    $tenant = createTenant();
    $variant = ShopKit::variant($tenant, ['price' => 8000], stock: 5);
    $client = ShopKit::client($tenant);
    $cart = ShopKit::cart($tenant, $client, [[$variant, 1]]);
    $order = asTenant($tenant, fn () => app(PlaceOrder::class)->handle($cart, ['fulfilment' => 'pickup', 'branch_id' => ShopKit::branch($tenant)->id, 'payment_method' => 'cod'], $client));
    asTenant($tenant, function () use ($order) {
        app(ConfirmOrder::class)->handle($order);
        app(MarkReadyForPickup::class)->handle($order);
        app(MarkDelivered::class)->handle($order, 'cash');
        app(ReturnOrder::class)->handle($order, asStoreCredit: true);
    });

    expect($order->fresh()->status)->toBe(OrderStatus::Returned);
    expect(ShopKit::available($tenant, $variant)['qty'])->toBe(5);
    expect($client->fresh()->wallet_balance)->toBe(8000);
});

it('rejects invalid transitions', function () {
    $tenant = createTenant();
    $variant = ShopKit::variant($tenant, stock: 5);
    $client = ShopKit::client($tenant);
    $cart = ShopKit::cart($tenant, $client, [[$variant, 1]]);
    $order = asTenant($tenant, fn () => app(PlaceOrder::class)->handle($cart, ['fulfilment' => 'pickup', 'branch_id' => ShopKit::branch($tenant)->id, 'payment_method' => 'cod'], $client));

    expect(fn () => asTenant($tenant, fn () => app(MarkDelivered::class)->handle($order)))->toThrow(DomainException::class);
    asTenant($tenant, fn () => app(ConfirmOrder::class)->handle($order));
    expect(fn () => asTenant($tenant, fn () => app(MarkOutForDelivery::class)->handle($order)))->toThrow(DomainException::class); // pickup order
});
