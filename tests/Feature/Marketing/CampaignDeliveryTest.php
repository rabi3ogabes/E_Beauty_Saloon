<?php

use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Actions\LaunchCampaign;
use App\Domain\Marketing\Actions\TrackCampaignClick;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Domain\Marketing\Models\TenantChannelSetting;
use App\Domain\Marketing\Services\MarketingScheduler;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;
use Tests\Feature\Marketing\MarketingFixtures as F;

beforeEach(function () {
    MessagingManager::fake();
    $this->tenant = createTenant();
});

it('delays messages inside quiet hours and sends them when the window ends', function () {
    $this->travelTo(now()->setDate(2026, 11, 22)->setTime(19, 30)); // 22:30 in Doha (UTC+3)

    asTenant($this->tenant, function () {
        F::client();
        app(LaunchCampaign::class)->handle(F::campaign(F::segment()));

        $message = CampaignMessage::first();
        expect(FakeChannel::$sent)->toBeEmpty()
            ->and($message->status)->toBe('skipped_quiet_hours')
            ->and($message->scheduled_for->toDateTimeString())->toBe('2026-11-23 06:00:00'); // 09:00 Doha
    });

    $this->travelTo(now()->setDate(2026, 11, 23)->setTime(6, 5));
    app(MarketingScheduler::class)->frequent();

    asTenant($this->tenant, function () {
        expect(FakeChannel::sentTo('whatsapp'))->toHaveCount(1)
            ->and(CampaignMessage::first()->status)->toBe('sent');
    });
});

it('unsubscribes a client from the link in a campaign message', function () {
    $token = asTenant($this->tenant, function () {
        F::noQuietHours($this->tenant);
        F::client();
        app(LaunchCampaign::class)->handle(F::campaign(F::segment()));

        return CampaignMessage::first()->token;
    });

    $this->get(tenantUrl($this->tenant, 'u/'.$token))->assertOk()->assertSee(__('marketing.unsubscribe.all'));
    $this->post(tenantUrl($this->tenant, 'u/'.$token), ['scope' => 'all'])->assertOk()->assertSee(__('marketing.unsubscribe.done_title'));

    asTenant($this->tenant, function () {
        $client = Client::first();
        expect($client->hasConsent('whatsapp'))->toBeFalse()->and($client->hasConsent('sms'))->toBeFalse()->and($client->hasConsent('email'))->toBeFalse();
    });
});

it('unsubscribes a client who replies STOP on WhatsApp and records delivery statuses', function () {
    $client = asTenant($this->tenant, function () {
        F::noQuietHours($this->tenant);
        TenantChannelSetting::create(['channel' => 'whatsapp', 'provider' => 'meta', 'mode' => 'own',
            'credentials' => ['phone_number_id' => 'PN-123', 'token' => 't', 'app_secret' => 'top-secret']]);
        $client = F::client();
        app(LaunchCampaign::class)->handle(F::campaign(F::segment()));
        CampaignMessage::first()->update(['provider_message_id' => 'wamid.ABC']);

        return $client;
    });

    $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
        'metadata' => ['phone_number_id' => 'PN-123'],
        'statuses' => [['id' => 'wamid.ABC', 'status' => 'delivered', 'timestamp' => (string) now()->timestamp], ['id' => 'wamid.ABC', 'status' => 'read', 'timestamp' => (string) now()->timestamp]],
        'messages' => [['from' => ltrim($client->phone, '+'), 'type' => 'text', 'text' => ['body' => ' Stop ']]],
    ]]]]]]);
    $signature = 'sha256='.hash_hmac('sha256', $payload, 'top-secret');

    $this->call('POST', centralUrl('webhooks/whatsapp'), [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)
        ->assertOk()->assertJson(['statuses' => 2, 'unsubscribed' => 1]);

    asTenant($this->tenant, function () use ($client) {
        $message = CampaignMessage::first();
        expect($message->status)->toBe('read')->and($message->delivered_at)->not->toBeNull()
            ->and($client->fresh()->hasConsent('whatsapp'))->toBeFalse()
            ->and($client->fresh()->hasConsent('sms'))->toBeTrue();
    });
});

it('rejects WhatsApp webhooks with an invalid signature', function () {
    asTenant($this->tenant, fn () => TenantChannelSetting::create(['channel' => 'whatsapp', 'provider' => 'meta', 'credentials' => ['phone_number_id' => 'PN-1', 'token' => 't', 'app_secret' => 'right']]));
    $payload = json_encode(['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => 'PN-1'], 'statuses' => []]]]]]]);

    $this->call('POST', centralUrl('webhooks/whatsapp'), [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'wrong'), 'CONTENT_TYPE' => 'application/json'], $payload)
        ->assertStatus(401);
    $this->call('POST', centralUrl('webhooks/whatsapp'), [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(401);

    $this->get(centralUrl('webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=nope&hub_challenge=42'))->assertForbidden();
});

it('runs an A/B test on 20% of the audience and sends the winner to the rest', function () {
    asTenant($this->tenant, function () {
        F::noQuietHours($this->tenant);
        foreach (range(1, 10) as $i) {
            F::client();
        }
        $campaign = F::campaign(F::segment(), ['ab_enabled' => true, 'ab_test_percent' => 20, 'ab_metric' => 'click', 'ab_wait_hours' => 2]);
        $campaign->variants()->create(['key' => 'A', 'content' => ['en' => ['body' => 'Variant A {link}']]]);
        $campaign->variants()->create(['key' => 'B', 'content' => ['en' => ['body' => 'Variant B {link}']]]);

        app(LaunchCampaign::class)->handle($campaign);

        expect($campaign->fresh()->status)->toBe('testing')
            ->and(FakeChannel::sentTo('whatsapp'))->toHaveCount(2)
            ->and(CampaignMessage::where('status', 'held')->count())->toBe(8);

        // B gets the click
        $b = CampaignMessage::whereHas('variant', fn ($q) => $q->where('key', 'B'))->first();
        app(TrackCampaignClick::class)->handle($b);
    });

    $this->travel(3)->hours();
    app(MarketingScheduler::class)->frequent();

    asTenant($this->tenant, function () {
        $campaign = Campaign::first();
        expect($campaign->status)->toBe('sent')
            ->and($campaign->variants()->where('is_winner', true)->value('key'))->toBe('B')
            ->and(FakeChannel::sentTo('whatsapp'))->toHaveCount(10)
            ->and(collect(FakeChannel::sentTo('whatsapp'))->filter(fn ($s) => str_starts_with($s['message']->body, 'Variant B'))->count())->toBe(9);
    });
});

it('launches scheduled campaigns from the scheduler only once', function () {
    asTenant($this->tenant, function () {
        F::noQuietHours($this->tenant);
        F::client();
        F::campaign(F::segment(), ['status' => 'scheduled', 'scheduled_at' => now()->addHour()]);
    });

    app(MarketingScheduler::class)->frequent();
    expect(FakeChannel::$sent)->toBeEmpty();

    $this->travel(61)->minutes();
    app(MarketingScheduler::class)->frequent();
    app(MarketingScheduler::class)->frequent();

    expect(FakeChannel::sentTo('whatsapp'))->toHaveCount(1);
});
