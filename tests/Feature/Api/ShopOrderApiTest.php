<?php

use App\Domain\Messaging\MessagingManager;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Shop\Models\Order;
use Tests\Feature\Shop\ShopKit;

require_once __DIR__.'/ApiHelpers.php';

beforeEach(function () {
    MessagingManager::fake();
    GatewayManager::fake();
    $this->tenant = createTenant();
    $this->variant = ShopKit::variant($this->tenant, ['name' => ['en' => 'Bond Oil', 'ar' => 'زيت'], 'price' => 12000], stock: 5);
    $this->client = ShopKit::client($this->tenant);
    $this->h = apiHeaders($this->tenant, clientToken($this->client));
});

it('lists products with Money prices and resolves a product by id or slug', function () {
    $this->getJson(apiUrl('shop/products'), apiHeaders($this->tenant))->assertOk()
        ->assertJsonPath('data.0.price.amount', 12000)->assertJsonPath('data.0.default_variant_id', $this->variant->id)->assertJsonPath('meta.per_page', 24);
    $this->getJson(apiUrl('shop/products/'.$this->variant->product_id), apiHeaders($this->tenant))->assertOk()->assertJsonPath('data.slug', $this->variant->product->slug);
    $this->getJson(apiUrl('shop/products/'.$this->variant->product->slug), apiHeaders($this->tenant))->assertOk()->assertJsonPath('data.id', $this->variant->product_id);
});

it('places a pickup order from the app cart lines (cash at pickup)', function () {
    $branch = ShopKit::branch($this->tenant);
    $res = $this->postJson(apiUrl('shop/orders'), [
        'items' => [['variant_id' => $this->variant->id, 'qty' => 2]], 'fulfilment' => 'pickup', 'branch_id' => $branch->id,
    ], $this->h)->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.total', 24000)
        ->assertJsonPath('data.payment.method', 'cod')->assertJsonPath('data.checkout_url', null);

    $order = asTenant($this->tenant, fn () => Order::withoutGlobalScopes()->firstOrFail());
    expect($order->client_id)->toBe($this->client->id)->and($order->customer_phone)->toBe($this->client->phone);

    $this->getJson(apiUrl('shop/orders'), $this->h)->assertOk()->assertJsonPath('data.0.number', $res->json('data.number'));
    $this->getJson(apiUrl('shop/orders/'.$res->json('data.number')), $this->h)->assertOk()->assertJsonPath('data.items.0.qty', 2);
});

it('places an online order from the X-Cart-Token cart and returns the hosted payment page', function () {
    ShopKit::gateway($this->tenant, 'tap');
    $token = $this->postJson(apiUrl('shop/cart/items'), ['variant_id' => $this->variant->id, 'qty' => 1], apiHeaders($this->tenant))->assertOk()->json('token');

    $this->postJson(apiUrl('shop/orders'), ['fulfilment' => 'pickup', 'branch_id' => ShopKit::branch($this->tenant)->id, 'payment_method' => 'online'], $this->h + ['X-Cart-Token' => $token, 'Idempotency-Key' => 'order-1'])
        ->assertCreated()->assertJsonPath('data.payment.method', 'online')->assertJsonPath('data.checkout_url', fn ($url) => str_contains((string) $url, '/payments/fake/'));
    $this->postJson(apiUrl('shop/orders'), ['fulfilment' => 'pickup', 'branch_id' => ShopKit::branch($this->tenant)->id, 'payment_method' => 'online'], $this->h + ['X-Cart-Token' => $token, 'Idempotency-Key' => 'order-1'])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(asTenant($this->tenant, fn () => Order::withoutGlobalScopes()->count()))->toBe(1);
});

it('rejects empty carts, unavailable stock and other clients\' orders', function () {
    $this->postJson(apiUrl('shop/orders'), ['fulfilment' => 'pickup'], $this->h)->assertStatus(422)->assertJsonPath('message', __('shop.errors.cart_empty'));
    $this->postJson(apiUrl('shop/orders'), ['items' => [['variant_id' => $this->variant->id, 'qty' => 9]], 'fulfilment' => 'pickup'], $this->h)->assertStatus(422);

    $this->postJson(apiUrl('shop/orders'), ['items' => [['variant_id' => $this->variant->id]], 'fulfilment' => 'pickup', 'branch_id' => ShopKit::branch($this->tenant)->id], $this->h)->assertCreated();
    $number = asTenant($this->tenant, fn () => Order::withoutGlobalScopes()->value('number'));
    $other = ShopKit::client($this->tenant, '+97455550999');
    $this->app['auth']->forgetGuards();
    $this->getJson(apiUrl('shop/orders/'.$number), apiHeaders($this->tenant, clientToken($other)))->assertNotFound();
});

it('gates shop orders behind the shop module', function () {
    withoutModule($this->tenant, 'shop');
    $this->postJson(apiUrl('shop/orders'), ['fulfilment' => 'pickup'], $this->h)->assertForbidden()->assertJsonPath('module', 'shop')->assertJsonPath('error.code', 'module_disabled');
});
