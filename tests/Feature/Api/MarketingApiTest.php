<?php

use App\Domain\Api\Models\ClientDevice;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Domain\Marketing\Models\ClientPackage;
use App\Domain\Marketing\Models\Package;
use App\Domain\Marketing\Models\Referral;
use App\Domain\Marketing\Services\MarketingMessenger;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Payments\Actions\SettlePayment;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\TenantPaymentGateway;
use Tests\Feature\Marketing\MarketingFixtures as F;

require_once __DIR__.'/ApiHelpers.php';

beforeEach(function () {
    MessagingManager::fake();
    GatewayManager::fake();
    $this->tenant = createTenant();
    [$this->package, $this->client] = asTenant($this->tenant, function () {
        TenantPaymentGateway::create(['driver' => 'sadad', 'label' => 'SADAD', 'is_active' => true, 'enabled_for' => ['shop', 'booking'], 'credentials' => ['merchant_id' => 'x']]);
        $service = F::service(15000);
        $package = Package::create(['name' => ['en' => '6 blow-dries', 'ar' => '6 استشوار'], 'slug' => 'six-blowdries', 'pricing' => 'percent', 'discount_bp' => 2000, 'sessions_count' => 6, 'validity_days' => 180]);
        $package->items()->create(['itemable_type' => $service->getMorphClass(), 'itemable_id' => $service->id, 'quantity' => 6]);

        return [$package, F::client([], ['whatsapp', 'push'])];
    });
    $this->h = apiHeaders($this->tenant, clientToken($this->client));
});

it('lists packages for sale with price, value and items', function () {
    $this->getJson(apiUrl('packages'), apiHeaders($this->tenant))->assertOk()
        ->assertJsonPath('data.0.name', '6 blow-dries')->assertJsonPath('data.0.price.amount', 72000)
        ->assertJsonPath('data.0.value.amount', 90000)->assertJsonPath('data.0.savings.amount', 18000)
        ->assertJsonPath('data.0.sessions', 6)->assertJsonPath('data.0.items.0.type', 'service')->assertJsonPath('data.0.items.0.quantity', 6);
});

it('buys a package: hosted checkout, activation by the payment, then shows it in my packages', function () {
    $res = $this->postJson(apiUrl("packages/{$this->package->id}/purchase"), [], $this->h + ['Idempotency-Key' => 'pkg-1'])
        ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.amount.amount', 72000);
    expect($res->json('data.checkout_url'))->toContain('/payments/fake/');
    $this->postJson(apiUrl("packages/{$this->package->id}/purchase"), [], $this->h + ['Idempotency-Key' => 'pkg-1'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect(asTenant($this->tenant, fn () => ClientPackage::count()))->toBe(1);

    asTenant($this->tenant, fn () => app(SettlePayment::class)->succeed(Payment::firstOrFail()));

    $this->getJson(apiUrl('me/packages'), $this->h)->assertOk()
        ->assertJsonPath('data.0.status', 'active')->assertJsonPath('data.0.usable', true)
        ->assertJsonPath('data.0.sessions_remaining', 6)->assertJsonPath('data.0.package.name', '6 blow-dries');
});

it('refuses packages that are not sold online', function () {
    asTenant($this->tenant, fn () => $this->package->update(['sellable_online' => false]));
    $this->postJson(apiUrl("packages/{$this->package->id}/purchase"), [], $this->h)->assertStatus(422)->assertJsonPath('message', __('marketing.packages.errors.not_available'));
    $this->getJson(apiUrl('packages'), apiHeaders($this->tenant))->assertOk()->assertJsonCount(0, 'data');
});

it('registers a push device and marketing push resolves to the latest token', function () {
    $messenger = app(MarketingMessenger::class);
    expect(asTenant($this->tenant, fn () => $messenger->contactFor($this->client, 'push')))->toBeNull();

    $this->postJson(apiUrl('devices'), ['token' => 'fcm-old', 'platform' => 'android'], $this->h)->assertCreated();
    $this->travel(1)->minutes();
    $this->postJson(apiUrl('devices'), ['token' => 'fcm-new', 'platform' => 'ios', 'app_version' => '1.4.0'], $this->h)->assertCreated()->assertJsonPath('data.platform', 'ios');

    asTenant($this->tenant, function () use ($messenger) {
        expect($messenger->contactFor($this->client, 'push'))->toBe('fcm-new')
            ->and($messenger->reachableChannels($this->client->fresh(), ['push', 'whatsapp']))->toBe(['push', 'whatsapp']);
    });

    $this->deleteJson(apiUrl('devices/fcm-new'), [], $this->h)->assertNoContent();
    expect(asTenant($this->tenant, fn () => $messenger->contactFor($this->client, 'push')))->toBe('fcm-old');

    // Same token re-registered by another client of the salon moves to that client.
    $other = asTenant($this->tenant, fn () => F::client());
    $this->app['auth']->forgetGuards();
    $this->postJson(apiUrl('devices'), ['token' => 'fcm-old', 'platform' => 'android'], apiHeaders($this->tenant, clientToken($other)))->assertCreated();
    expect(asTenant($this->tenant, fn () => ClientDevice::where('token', 'fcm-old')->value('client_id')))->toBe($other->id);

    $this->postJson(apiUrl('devices'), ['token' => 'x', 'platform' => 'symbian'], $this->h)->assertStatus(422)->assertJsonValidationErrors('platform');
});

it('keeps device tokens per salon', function () {
    $this->postJson(apiUrl('devices'), ['token' => 'shared-token', 'platform' => 'ios'], $this->h)->assertCreated();
    $b = createTenant();
    $bClient = asTenant($b, fn () => F::client());
    $this->app['auth']->forgetGuards();
    $this->postJson(apiUrl('devices'), ['token' => 'shared-token', 'platform' => 'ios'], apiHeaders($b, clientToken($bClient)))->assertCreated();

    expect(ClientDevice::withoutGlobalScopes()->where('token', 'shared-token')->count())->toBe(2)
        ->and(asTenant($this->tenant, fn () => ClientDevice::latestTokenFor($this->client)))->toBe('shared-token');
});

it('serves the notification inbox from campaign messages that reached the client', function () {
    asTenant($this->tenant, function () {
        $campaign = F::campaign(F::segment());
        CampaignMessage::create(['campaign_id' => $campaign->id, 'client_id' => $this->client->id, 'status' => 'delivered', 'channel' => 'push', 'sent_at' => now()]);
        $skipped = F::campaign(F::segment(), ['name' => 'Skipped']);
        CampaignMessage::create(['campaign_id' => $skipped->id, 'client_id' => $this->client->id, 'status' => 'skipped_no_consent']);
    });

    $res = $this->getJson(apiUrl('notifications'), $this->h)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Spring glow')->assertJsonPath('data.0.read', false)->assertJsonPath('data.0.channel', 'push');
    expect($res->json('data.0.body'))->toStartWith('Hi Client')->toContain('/c/');
});

it('shares the referral code, link and reward rules', function () {
    asTenant($this->tenant, function () {
        $friend = F::client();
        Referral::create(['referrer_client_id' => $this->client->id, 'referred_client_id' => $friend->id, 'status' => 'rewarded', 'points_awarded' => 500]);
    });

    $this->getJson(apiUrl('me/referral'), $this->h)->assertOk()
        ->assertJsonPath('data.code', $this->client->referral_code)
        ->assertJsonPath('data.rewards.referrer_points', 500)->assertJsonPath('data.rewards.friend_discount_percent', 15)
        ->assertJsonPath('data.stats.rewarded', 1)->assertJsonPath('data.stats.points_earned', 500)
        ->assertJsonPath('data.link', fn ($link) => str_ends_with($link, '/r/'.$this->client->referral_code));
});

it('gates packages and the inbox behind the marketing module', function () {
    withoutModule($this->tenant, 'marketing');
    $this->getJson(apiUrl('packages'), apiHeaders($this->tenant))->assertForbidden()->assertJsonPath('module', 'marketing');
    $this->getJson(apiUrl('notifications'), $this->h)->assertForbidden()->assertJsonPath('error.code', 'module_disabled');
    $this->postJson(apiUrl('devices'), ['token' => 'still-works', 'platform' => 'ios'], $this->h)->assertCreated();
});
