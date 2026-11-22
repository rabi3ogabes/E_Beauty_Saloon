<?php

use App\Domain\Messaging\MessagingManager;
use App\Domain\Shop\Actions\PlaceOrder;
use App\Domain\Shop\Events\AbandonedCartDetected;
use App\Domain\Shop\Jobs\DetectAbandonedCarts;
use App\Domain\Shop\Models\Cart;
use App\Domain\Shop\Models\Order;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Shop\ShopKit;

beforeEach(fn () => MessagingManager::fake());

it('tenant isolation for carts and orders', function () {
    $a = createTenant(slug: 'alpha');
    $b = createTenant(slug: 'beta');
    $va = ShopKit::variant($a, stock: 5);
    $vb = ShopKit::variant($b, stock: 5);
    $ca = ShopKit::client($a, '+97455551111');
    $cb = ShopKit::client($b, '+97455552222');
    $cartA = ShopKit::cart($a, $ca, [[$va, 1]]);
    ShopKit::cart($b, $cb, [[$vb, 1]]);
    $orderA = asTenant($a, fn () => app(PlaceOrder::class)->handle($cartA, ['fulfilment' => 'pickup', 'branch_id' => ShopKit::branch($a)->id, 'payment_method' => 'cod'], $ca));

    expect(asTenant($b, fn () => Cart::count()))->toBe(1)
        ->and(asTenant($b, fn () => Order::count()))->toBe(0)
        ->and(asTenant($b, fn () => Cart::where('token', $cartA->token)->exists()))->toBeFalse()
        ->and(asTenant($b, fn () => Order::find($orderA->id)))->toBeNull();

    // A tenant's cart token / order link / variant are useless on another salon.
    $this->withCookie('shop_cart', $cartA->token)->getJson(tenantUrl($b, 'shop/cart'))->assertOk()->assertJsonPath('count', 0);
    $this->get(tenantUrl($b, 'shop/order/'.$orderA->public_token))->assertNotFound();
    $this->postJson(tenantUrl($b, 'shop/cart/items'), ['variant_id' => $va->id])->assertStatus(422);
    $this->actingAs($ca, 'client')->get(tenantUrl($b, 'account/orders'))->assertRedirect();
    $this->get(tenantUrl($a, 'shop/order/'.$orderA->public_token))->assertOk();
});

it('abandoned cart event dispatched', function () {
    Event::fake([AbandonedCartDetected::class]);
    $tenant = createTenant();
    $v = ShopKit::variant($tenant, ['price' => 7000]);
    $client = ShopKit::client($tenant);
    $idle = ShopKit::cart($tenant, $client, [[$v, 2]], ['last_activity_at' => now()->subHours(3)]);
    ShopKit::cart($tenant, null, [[$v, 1]], ['last_activity_at' => now()->subHours(3)]); // anonymous: no phone
    ShopKit::cart($tenant, null, [[$v, 1]], ['last_activity_at' => now()->subMinutes(30), 'phone' => '+97455553333']); // recent
    $guestWithPhone = ShopKit::cart($tenant, null, [[$v, 1]], ['last_activity_at' => now()->subHours(5), 'phone' => '+97455554444']);
    ShopKit::cart($tenant, $client, [], ['last_activity_at' => now()->subHours(3)]); // empty

    (new DetectAbandonedCarts)->handle(app(TenantContext::class));

    Event::assertDispatchedTimes(AbandonedCartDetected::class, 2);
    Event::assertDispatched(AbandonedCartDetected::class, fn ($e) => $e->cart->id === $idle->id && $e->clientId === $client->id && $e->phone === $client->phone && $e->subtotal === 14000 && str_contains($e->recoveryUrl, $idle->token));
    Event::assertDispatched(AbandonedCartDetected::class, fn ($e) => $e->cart->id === $guestWithPhone->id && $e->phone === '+97455554444');

    // Only once per idle period
    (new DetectAbandonedCarts)->handle(app(TenantContext::class));
    Event::assertDispatchedTimes(AbandonedCartDetected::class, 2);

    // Recovery link restores the cart in the browser.
    $this->get(tenantUrl($tenant, 'shop/cart/recover/'.$guestWithPhone->token))->assertRedirect()->assertCookie('shop_cart');
});

it('does not run shop jobs for tenants without the module', function () {
    Event::fake([AbandonedCartDetected::class]);
    $tenant = createTenant('essential');
    asTenant($tenant, fn () => Cart::create(['token' => 'x'.str_repeat('a', 30), 'phone' => '+97455550000', 'last_activity_at' => now()->subDay()]));
    (new DetectAbandonedCarts)->handle(app(TenantContext::class));
    Event::assertNotDispatched(AbandonedCartDetected::class);
});
