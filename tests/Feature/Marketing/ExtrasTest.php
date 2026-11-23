<?php

use App\Domain\Marketing\Actions\EnrollMembership;
use App\Domain\Marketing\Automation\AutomationEngine;
use App\Domain\Marketing\Models\Automation;
use App\Domain\Marketing\Models\AutomationRun;
use App\Domain\Marketing\Models\Membership;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Models\TenantChannelSetting;
use App\Domain\Marketing\Services\MarketingScheduler;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\MessagingManager;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Marketing\MarketingFixtures as F;

it('unsubscribes from SMS when a client texts STOP to the inbound gateway webhook', function () {
    MessagingManager::fake();
    $tenant = createTenant();
    $client = asTenant($tenant, function () {
        TenantChannelSetting::create(['channel' => 'sms', 'provider' => 'http', 'credentials' => ['url' => 'https://sms.test', 'inbound_secret' => 'in-secret']]);

        return F::client();
    });

    $this->post(tenantUrl($tenant, 'webhooks/marketing/sms/wrong'), ['from' => $client->phone, 'text' => 'STOP'])->assertForbidden();
    $this->post(tenantUrl($tenant, 'webhooks/marketing/sms/in-secret'), ['From' => $client->phone, 'Body' => 'hello'])->assertJson(['unsubscribed' => false]);
    $this->post(tenantUrl($tenant, 'webhooks/marketing/sms/in-secret'), ['from' => $client->phone, 'text' => 'إيقاف'])->assertJson(['unsubscribed' => true]);

    asTenant($tenant, fn () => expect($client->fresh()->hasConsent('sms'))->toBeFalse()->and($client->fresh()->hasConsent('whatsapp'))->toBeTrue());
});

it('sends WhatsApp template messages through the Meta Cloud API driver', function () {
    MessagingManager::fake(false);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.XYZ']]])]);
    $tenant = createTenant();

    asTenant($tenant, function () {
        TenantChannelSetting::create(['channel' => 'whatsapp', 'provider' => 'meta', 'credentials' => ['phone_number_id' => '1234', 'token' => 'tok']]);
        $result = app(MessagingManager::class)->send('whatsapp', new OutboundMessage('+97455001234', 'Hi', template: 'spring_glow', variables: ['Noura', 'https://x/c/abc'],
            mediaUrl: 'https://cdn.test/banner.png', buttons: [['type' => 'url', 'text' => 'Book', 'param' => 'abc']], locale: 'ar'));

        expect($result->ok)->toBeTrue()->and($result->providerMessageId)->toBe('wamid.XYZ');
    });

    Http::assertSent(function ($request) {
        $components = collect($request['template']['components']);

        return str_ends_with($request->url(), '/1234/messages') && $request->hasHeader('Authorization', 'Bearer tok')
            && $request['to'] === '97455001234' && $request['template']['language']['code'] === 'ar'
            && $components->firstWhere('type', 'header')['parameters'][0]['image']['link'] === 'https://cdn.test/banner.png'
            && $components->firstWhere('type', 'body')['parameters'][0]['text'] === 'Noura'
            && $components->firstWhere('type', 'button')['parameters'][0]['text'] === 'abc';
    });
    MessagingManager::fake();
});

it('shows the live flash sale countdown component', function () {
    $tenant = createTenant();
    asTenant($tenant, function () {
        expect(trim(Blade::render('<x-marketing.flash-sale />')))->toBe('');
        Promotion::create(['name' => ['en' => 'White Friday'], 'type' => 'flash_sale', 'discount_value' => 2500, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'show_countdown' => true]);
        expect(Blade::render('<x-marketing.flash-sale />'))->toContain('White Friday')->toContain('25%');
    });
});

it('reminds members before their membership renews (membership_renewal recipe)', function () {
    MessagingManager::fake();
    $tenant = createTenant();
    F::noQuietHours($tenant);
    asTenant($tenant, function () {
        $membership = Membership::create(['name' => ['en' => 'Lumière Club'], 'monthly_price' => 59900, 'billing' => 'recurring']);
        $cm = app(EnrollMembership::class)->handle($membership, F::client());
        $cm->update(['renews_at' => now()->addDays(2)]);
        Automation::create(['name' => 'Renewal', 'recipe' => 'membership_renewal', 'trigger' => 'membership_renewal', 'trigger_config' => ['days_before' => 3], 'is_active' => true,
            'steps' => [['type' => 'send_message', 'channels' => ['whatsapp'], 'transactional' => true, 'body' => ['en' => 'Your {membership} renews on {renews}']]]]);
    });

    app(MarketingScheduler::class)->daily();

    expect(FakeChannel::sentTo('whatsapp'))->toHaveCount(1)
        ->and(FakeChannel::sentTo('whatsapp')[0]['message']->body)->toContain('Lumière Club');
});

it('waits for quiet hours before an automation sends', function () {
    MessagingManager::fake();
    $this->travelTo(now()->setDate(2026, 11, 22)->setTime(20, 0)); // 23:00 Doha
    $tenant = createTenant();
    asTenant($tenant, function () {
        Automation::create(['name' => 'Cart', 'trigger' => 'abandoned_cart', 'is_active' => true, 'steps' => [['type' => 'send_message', 'body' => ['en' => 'Your bag {cart_url}']]]]);
        $run = app(AutomationEngine::class)->fire('abandoned_cart', F::client(), ['cart_url' => 'https://x/cart'])[0];
        expect($run->status)->toBe('waiting')->and($run->resume_at->toDateTimeString())->toBe('2026-11-23 06:00:00')->and(FakeChannel::$sent)->toBeEmpty();
    });

    $this->travelTo(now()->setDate(2026, 11, 23)->setTime(6, 1));
    app(MarketingScheduler::class)->frequent();
    asTenant($tenant, fn () => expect(AutomationRun::first()->status)->toBe('completed'));
    expect(FakeChannel::sentTo('whatsapp'))->toHaveCount(1);
});

it('renders the loyalty and referral account sections for a client', function () {
    $tenant = createTenant();
    $client = asTenant($tenant, fn () => F::client(['loyalty_points' => 2400, 'loyalty_tier' => 'gold']));

    $this->actingAs($client, 'client')->get(tenantUrl($tenant, 'account/loyalty'))->assertOk()->assertSee(__('marketing.loyalty.tiers.gold'))->assertSee('2,400');
    $this->actingAs($client, 'client')->get(tenantUrl($tenant, 'account/referrals'))->assertOk()->assertSee('/r/'.$client->referral_code);
});

it('renders marketing screens in Arabic', function () {
    $tenant = createTenant();
    $tenant->owner->update(['locale' => 'ar']);

    $this->actingAs($tenant->owner)->get(tenantUrl($tenant, 'dashboard/marketing/campaigns?lang=ar'))->assertOk();
    $this->get(tenantUrl($tenant, 'gift-cards?lang=ar'))->assertOk()->assertSee('بطاقات الهدايا');
});
