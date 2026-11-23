<?php

use App\Domain\Marketing\Actions\ApplyPromotions;
use App\Domain\Marketing\Actions\PurchasePackage;
use App\Domain\Marketing\Actions\RecordPromotionRedemption;
use App\Domain\Marketing\Actions\UsePackageSession;
use App\Domain\Marketing\Models\ClientPackage;
use App\Domain\Marketing\Models\GiftCardOrder;
use App\Domain\Marketing\Models\Package;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Payments\Actions\SettlePayment;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Payments\Models\GiftCard;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Support\Exceptions\DomainException;
use Tests\Feature\Marketing\MarketingFixtures as F;

beforeEach(function () {
    MessagingManager::fake();
    GatewayManager::fake();
    $this->tenant = createTenant();
    asTenant($this->tenant, fn () => TenantPaymentGateway::create(['driver' => 'sadad', 'label' => 'SADAD', 'is_active' => true, 'enabled_for' => ['shop', 'booking'], 'credentials' => ['merchant_id' => 'x']]));
});

it('sells a package online, activates it when paid and redeems sessions', function () {
    [$package, $client] = asTenant($this->tenant, function () {
        $service = F::service(15000);
        $package = Package::create(['name' => ['en' => '6 blow-dries', 'ar' => '6 استشوار'], 'slug' => 'six-blowdries', 'pricing' => 'percent', 'discount_bp' => 2000, 'sessions_count' => 6, 'validity_days' => 180]);
        $package->items()->create(['itemable_type' => $service->getMorphClass(), 'itemable_id' => $service->id, 'quantity' => 6]);

        return [$package, F::client()];
    });

    $response = $this->actingAs($client, 'client')->post(tenantUrl($this->tenant, 'packages/'.$package->id.'/buy'));
    $response->assertRedirectContains('/payments/fake/');

    asTenant($this->tenant, function () {
        $cp = ClientPackage::first();
        expect($cp->status)->toBe('pending')->and($cp->price_paid)->toBe(72000); // 6 × 150 − 20%

        app(SettlePayment::class)->succeed(Payment::first());
        $cp->refresh();
        expect($cp->status)->toBe('active')->and($cp->expires_at->isFuture())->toBeTrue()->and($cp->sessions_remaining)->toBe(6);

        $use = app(UsePackageSession::class);
        $use->handle($cp, 1, key: 'package:booking:1');
        $use->handle($cp, 1, key: 'package:booking:1'); // idempotent
        expect($cp->fresh()->sessions_remaining)->toBe(5);

        $use->handle($cp, 5);
        expect($cp->fresh()->status)->toBe('used_up');
        expect(fn () => $use->handle($cp->fresh(), 1))->toThrow(DomainException::class);
    });

    $this->actingAs($client, 'client')->get(tenantUrl($this->tenant, 'account/packages'))->assertOk()->assertSee('6 blow-dries');
});

it('sells a package at the POS as active immediately', function () {
    asTenant($this->tenant, function () {
        $package = Package::create(['name' => ['en' => 'Bridal Glow'], 'slug' => 'bridal', 'price' => 300000, 'sessions_count' => 3]);
        [$cp, $payment] = app(PurchasePackage::class)->handle($package, F::client(), 'pos');
        expect($payment)->toBeNull()->and($cp->status)->toBe('active')->and($cp->price_paid)->toBe(300000);
    });
});

it('buys a gift card online: pending until paid, then active and delivered', function () {
    $this->get(tenantUrl($this->tenant, 'gift-cards'))->assertOk()->assertSee(__('marketing.gift_cards.title'));

    $this->post(tenantUrl($this->tenant, 'gift-cards'), [
        'amount' => 500, 'design' => 'noir', 'purchaser_name' => 'Aisha', 'purchaser_email' => 'aisha@example.com',
        'recipient_name' => 'Maryam', 'recipient_phone' => '5555 7777', 'recipient_email' => 'maryam@example.com', 'message' => 'Happy birthday!',
    ])->assertRedirectContains('/payments/fake/');

    $url = asTenant($this->tenant, function () {
        $order = GiftCardOrder::first();
        $card = GiftCard::first();
        expect($order->status)->toBe('pending')->and($card->status)->toBe('pending')->and($card->initial_amount)->toBe(50000)->and($card->design)->toBe('noir');

        app(SettlePayment::class)->succeed(Payment::first());

        expect($card->fresh()->status)->toBe('active')->and($order->fresh()->status)->toBe('paid')->and($order->fresh()->delivered_at)->not->toBeNull();
        $whatsapp = FakeChannel::sentTo('whatsapp', '+97455557777');
        expect($whatsapp)->toHaveCount(1)->and(FakeChannel::sentTo('email', 'maryam@example.com'))->toHaveCount(1);
        preg_match('#https?://\S+/gift-cards/'.$card->code.'\S*#', $whatsapp[0]['message']->body, $m);

        return $m[0];
    });

    $this->get($url)->assertOk()->assertSee('Maryam')->assertSee('Happy birthday!');
    $this->get(tenantUrl($this->tenant, 'gift-cards/'.asTenant($this->tenant, fn () => GiftCard::first()->code)))->assertForbidden();
});

it('prices a cart with promotions (ApplyPromotions contract)', function () {
    asTenant($this->tenant, function () {
        $client = F::client(['birthday' => now()->subYears(28)]);
        Promotion::create(['name' => ['en' => 'Glow 15'], 'type' => 'coupon', 'code' => 'glow15', 'discount_type' => 'percent', 'discount_value' => 1500]);
        Promotion::create(['name' => ['en' => 'Birthday'], 'type' => 'birthday', 'discount_type' => 'percent', 'discount_value' => 2000, 'rules' => ['applies_to' => 'services']]);
        Promotion::create(['name' => ['en' => 'Morning'], 'type' => 'happy_hour', 'discount_type' => 'fixed', 'discount_value' => 5000, 'rules' => ['from' => '10:00', 'to' => '12:00']]);
        Promotion::create(['name' => ['en' => '2+1 polish'], 'type' => 'bogo', 'rules' => ['applies_to' => 'products', 'buy_quantity' => 2, 'get_quantity' => 1, 'get_discount_bp' => 10000]]);
        $lines = [['key' => 's', 'type' => 'service', 'id' => 1, 'price' => 20000], ['key' => 'p', 'type' => 'product', 'id' => 9, 'price' => 3000, 'quantity' => 3]];

        $apply = app(ApplyPromotions::class);
        // birthday (20% of 200 = 40) beats bogo (30) → plus coupon 15% of the whole cart (290 × 15% = 43.50)
        $r = $apply->handle($lines, $client, 'GLOW15', now()->setTime(18, 0)->utc());
        expect($r['subtotal'])->toBe(29000)
            ->and(collect($r['applied'])->pluck('type')->all())->toBe(['birthday', 'coupon'])
            ->and($r['discount'])->toBe(4000 + 4350)
            ->and($r['total'])->toBe(29000 - 8350)
            ->and($r['coupon_error'])->toBeNull();

        $bad = $apply->handle($lines, null, 'NOPE');
        expect($bad['coupon_error'])->not->toBeNull()->and(collect($bad['applied'])->pluck('type')->all())->toBe(['bogo']);

        // happy hour 10:00–12:00 Doha
        $morning = $apply->handle([['type' => 'service', 'id' => 1, 'price' => 20000]], null, null, now()->setTimezone('Asia/Qatar')->setTime(10, 30));
        expect(collect($morning['applied'])->pluck('type')->all())->toBe(['happy_hour'])->and($morning['discount'])->toBe(5000);

        // usage recorded through the companion action, and limits respected
        $coupon = Promotion::where('code', 'GLOW15')->first();
        $coupon->update(['usage_limit' => 1]);
        app(RecordPromotionRedemption::class)->handle([['promotion_id' => $coupon->id, 'amount' => 4350]], $client, $client);
        expect($coupon->fresh()->used_count)->toBe(1)
            ->and($apply->handle($lines, $client, 'GLOW15')['coupon_error'])->not->toBeNull();
    });
});
