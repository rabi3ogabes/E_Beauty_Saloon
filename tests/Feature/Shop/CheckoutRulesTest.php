<?php

use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Shop\Actions\PlaceOrder;
use App\Domain\Shop\Actions\QuoteCheckout;
use App\Domain\Shop\Actions\VerifyCodOtp;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\ShopCoupon;
use App\Domain\Shop\Support\CheckoutQuote;
use App\Domain\Shop\Support\FulfilmentPlanner;
use App\Domain\Shop\Support\ShopSettings;
use App\Support\Exceptions\DomainException;
use Tests\Feature\Shop\ShopKit;

beforeEach(fn () => MessagingManager::fake());

function quoteFor($tenant, array $lines, array $input): CheckoutQuote
{
    return asTenant($tenant, fn () => app(QuoteCheckout::class)->handle(array_merge([
        'items' => collect($lines)->map(fn ($l) => ['variant_id' => $l[0]->id, 'qty' => $l[1]])->all(),
    ], $input)));
}

it('applies zone fee, minimum order and free-shipping threshold rules', function () {
    $tenant = createTenant();
    $v = ShopKit::variant($tenant, ['price' => 4000], stock: 50);
    $zone = ShopKit::zone($tenant, ['fee' => 1500, 'min_order' => 5000, 'free_shipping_threshold' => 30000]);

    // Below the minimum order
    expect(fn () => quoteFor($tenant, [[$v, 1]], ['fulfilment' => 'delivery', 'zone_id' => $zone->id]))
        ->toThrow(DomainException::class, __('shop.errors.min_order', ['amount' => money(5000), 'zone' => 'West Bay']));

    // Normal fee
    $q = quoteFor($tenant, [[$v, 2]], ['fulfilment' => 'delivery', 'zone_id' => $zone->id]);
    expect($q->deliveryFee)->toBe(1500)->and($q->total)->toBe(9500);

    // Free above threshold
    $q = quoteFor($tenant, [[$v, 8]], ['fulfilment' => 'delivery', 'zone_id' => $zone->id]);
    expect($q->deliveryFee)->toBe(0)->and($q->total)->toBe(32000)->and($q->toArray()['free_shipping_gap'])->toBe(0);

    // Zone resolved from the area name and from the map pin (polygon)
    asTenant($tenant, fn () => $zone->update(['polygon' => [[25.30, 51.50], [25.35, 51.50], [25.35, 51.56], [25.30, 51.56]]]));
    expect(quoteFor($tenant, [[$v, 2]], ['fulfilment' => 'delivery', 'area' => 'dafna'])->zone->id)->toBe($zone->id);
    expect(quoteFor($tenant, [[$v, 2]], ['fulfilment' => 'delivery', 'lat' => 25.32, 'lng' => 51.53])->zone->id)->toBe($zone->id);
    expect(fn () => quoteFor($tenant, [[$v, 2]], ['fulfilment' => 'delivery', 'area' => 'Al Khor']))->toThrow(DomainException::class, __('shop.errors.no_zone'));

    // Same-day surcharge before cutoff, refused after
    $q = quoteFor($tenant, [[$v, 2]], ['fulfilment' => 'same_day', 'zone_id' => $zone->id]);
    expect($q->deliveryFee)->toBe(2500);
    asTenant($tenant, fn () => $zone->update(['same_day_cutoff' => '00:00']));
    expect(fn () => quoteFor($tenant, [[$v, 2]], ['fulfilment' => 'same_day', 'zone_id' => $zone->id]))->toThrow(DomainException::class);
});

it('requires a valid same-day slot when placing a same-day order', function () {
    $tenant = createTenant();
    $v = ShopKit::variant($tenant, ['price' => 10000], stock: 5);
    $zone = ShopKit::zone($tenant);
    $client = ShopKit::client($tenant);
    $this->travelTo(now('Asia/Qatar')->setTime(9, 0)->utc());

    $slots = asTenant($tenant, fn () => app(FulfilmentPlanner::class)->sameDaySlots($zone->fresh()));
    expect($slots)->not->toBeEmpty();

    $cart = ShopKit::cart($tenant, $client, [[$v, 1]]);
    expect(fn () => asTenant($tenant, fn () => app(PlaceOrder::class)->handle($cart, ['fulfilment' => 'same_day', 'zone_id' => $zone->id, 'area' => 'West Bay', 'delivery_slot' => '2000-01-01 10:00-12:00', 'payment_method' => 'cod'], $client)))
        ->toThrow(DomainException::class, __('shop.errors.slot_invalid'));

    $order = asTenant($tenant, fn () => app(PlaceOrder::class)->handle($cart, ['fulfilment' => 'same_day', 'zone_id' => $zone->id, 'area' => 'West Bay', 'delivery_slot' => $slots[0], 'payment_method' => 'cod'], $client));
    expect($order->delivery_slot)->toBe($slots[0])->and($order->delivery_fee)->toBe(2500);
});

it('enforces coupon rules: window, minimum order, usage limits and per-client limit', function () {
    $tenant = createTenant();
    $v = ShopKit::variant($tenant, ['price' => 10000], stock: 50);
    $branch = ShopKit::branch($tenant);
    asTenant($tenant, function () {
        ShopCoupon::create(['code' => 'VIP20', 'type' => 'percent', 'value' => 2000, 'min_order' => 15000, 'max_discount' => 5000, 'max_uses' => 2, 'max_uses_per_client' => 1]);
        ShopCoupon::create(['code' => 'OLD', 'type' => 'fixed', 'value' => 1000, 'expires_at' => now()->subDay()]);
        ShopCoupon::create(['code' => 'SOON', 'type' => 'fixed', 'value' => 1000, 'starts_at' => now()->addDay()]);
        ShopCoupon::create(['code' => 'FREESHIP', 'type' => 'fixed', 'value' => 0, 'free_shipping' => true]);
    });
    $pickup = ['fulfilment' => 'pickup', 'branch_id' => $branch->id];

    expect(fn () => quoteFor($tenant, [[$v, 1]], $pickup + ['coupon_code' => 'OLD']))->toThrow(DomainException::class, __('shop.errors.coupon_invalid'));
    expect(fn () => quoteFor($tenant, [[$v, 1]], $pickup + ['coupon_code' => 'SOON']))->toThrow(DomainException::class, __('shop.errors.coupon_invalid'));
    expect(fn () => quoteFor($tenant, [[$v, 1]], $pickup + ['coupon_code' => 'VIP20']))->toThrow(DomainException::class, __('shop.errors.coupon_min_order', ['amount' => money(15000)]));

    expect(quoteFor($tenant, [[$v, 2]], $pickup + ['coupon_code' => 'vip20'])->discount)->toBe(4000); // 20%
    expect(quoteFor($tenant, [[$v, 4]], $pickup + ['coupon_code' => 'VIP20'])->discount)->toBe(5000); // capped

    $zone = ShopKit::zone($tenant, ['fee' => 1500, 'free_shipping_threshold' => null]);
    expect(quoteFor($tenant, [[$v, 1]], ['fulfilment' => 'delivery', 'zone_id' => $zone->id, 'coupon_code' => 'FREESHIP'])->deliveryFee)->toBe(0);

    // Per client: once. Total: twice.
    $a = ShopKit::client($tenant, '+97455550001');
    $b = ShopKit::client($tenant, '+97455550002');
    $c = ShopKit::client($tenant, '+97455550003');
    $place = fn ($client) => asTenant($tenant, fn () => app(PlaceOrder::class)->handle(ShopKit::cart($tenant, $client, [[$v, 2]], ['coupon_code' => 'VIP20']), $pickup + ['payment_method' => 'cod'], $client));
    expect($place($a)->discount)->toBe(4000);
    expect(fn () => $place($a))->toThrow(DomainException::class, __('shop.errors.coupon_client_limit'));
    $place($b);
    expect(fn () => $place($c))->toThrow(DomainException::class, __('shop.errors.coupon_used_up'));
    expect(asTenant($tenant, fn () => ShopCoupon::where('code', 'VIP20')->value('uses_count')))->toBe(2);
});

it('enforces the COD limit per client and confirms COD orders by OTP', function () {
    $tenant = createTenant(slug: 'lumiere');
    asTenant($tenant, fn () => ShopSettings::save(['cod_limit' => 30000, 'cod_otp_required' => true]));
    $v = ShopKit::variant($tenant, ['price' => 20000], stock: 10);
    $branch = ShopKit::branch($tenant);
    $client = ShopKit::client($tenant);
    $pickup = ['fulfilment' => 'pickup', 'branch_id' => $branch->id, 'payment_method' => 'cod'];

    $order = asTenant($tenant, fn () => app(PlaceOrder::class)->handle(ShopKit::cart($tenant, $client, [[$v, 1]]), $pickup, $client));
    expect($order->cod_otp_required)->toBeTrue()->and($order->status)->toBe(OrderStatus::Pending);
    // OTP sent by WhatsApp alongside the "placed" message
    expect(collect(FakeChannel::sentTo('whatsapp', $client->phone))->pluck('message.meta.type')->all())->toContain('otp', 'shop_order');

    // A second open COD order would exceed 300 QAR outstanding.
    expect(fn () => asTenant($tenant, fn () => app(PlaceOrder::class)->handle(ShopKit::cart($tenant, $client, [[$v, 1]]), $pickup, $client)))
        ->toThrow(DomainException::class, __('shop.errors.cod_limit', ['limit' => money(30000)]));

    // Wrong code, then the right one confirms the order.
    expect(fn () => asTenant($tenant, fn () => app(VerifyCodOtp::class)->handle($order, '000000')))->toThrow(DomainException::class);
    $this->post(tenantUrl($tenant, 'shop/order/'.$order->public_token.'/otp'), ['code' => '123456'])->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)->and($order->fresh()->cod_otp_verified_at)->not->toBeNull();

    // COD disabled entirely
    asTenant($tenant, fn () => ShopSettings::save(['cod_enabled' => false]));
    expect(fn () => asTenant($tenant, fn () => app(PlaceOrder::class)->handle(ShopKit::cart($tenant, ShopKit::client($tenant, '+97455557777'), [[$v, 1]]), $pickup, null)))
        ->toThrow(DomainException::class);
});

it('blocks orders when the selected branch lacks stock', function () {
    $tenant = createTenant();
    $v = ShopKit::variant($tenant, stock: 1);
    $client = ShopKit::client($tenant);
    expect(fn () => asTenant($tenant, fn () => app(PlaceOrder::class)->handle(ShopKit::cart($tenant, $client, [[$v, 2]]), ['fulfilment' => 'pickup', 'branch_id' => ShopKit::branch($tenant)->id, 'payment_method' => 'cod'], $client)))
        ->toThrow(DomainException::class);
    expect(ShopKit::available($tenant, $v))->toBe(['qty' => 1, 'reserved' => 0]);
});
