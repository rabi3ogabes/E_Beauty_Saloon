<?php

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Marketing\Actions\LaunchCampaign;
use App\Domain\Marketing\Models\Banner;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Domain\Marketing\Models\WhatsappTemplate;
use App\Domain\Marketing\Services\CampaignReport;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;
use Tests\Feature\Marketing\MarketingFixtures as F;

beforeEach(function () {
    MessagingManager::fake();
    $this->tenant = createTenant();
    F::noQuietHours($this->tenant);
});

it('sends a campaign with a banner to a segment via a WhatsApp template', function () {
    asTenant($this->tenant, function () {
        $vip = F::client(['is_vip' => true]);
        F::client(); // not in the segment
        $banner = Banner::create(['name' => 'Spring', 'format' => 'whatsapp', 'exported_path' => 'banners/spring.png']);
        $template = WhatsappTemplate::create(['name' => 'spring_glow', 'language' => 'en', 'body' => 'Hi {{1}}, book: {{2}}', 'header_type' => 'image', 'status' => 'approved',
            'buttons' => [['type' => 'url', 'text' => 'Book now', 'url' => 'https://x.test/c/{{1}}']]]);
        $campaign = F::campaign(F::segment([['field' => 'vip']]), ['whatsapp_template_id' => $template->id, 'banner_id' => $banner->id]);

        app(LaunchCampaign::class)->handle($campaign);

        $sent = FakeChannel::sentTo('whatsapp');
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['message']->to)->toBe($vip->phone)
            ->and($sent[0]['message']->template)->toBe('spring_glow')
            ->and($sent[0]['message']->mediaUrl)->toEndWith('banners/spring.png')
            ->and($sent[0]['message']->variables[0])->toBe($vip->firstName())
            ->and($sent[0]['message']->buttons[0]['param'])->toBe(CampaignMessage::first()->token);
        expect($campaign->fresh()->status)->toBe('sent')
            ->and(CampaignMessage::first()->status)->toBe('sent')
            ->and(CampaignMessage::first()->channel)->toBe('whatsapp');
    });
});

it('falls back to SMS when WhatsApp fails', function () {
    asTenant($this->tenant, function () {
        $client = F::client();
        FakeChannel::$failFor['whatsapp'] = true;
        $campaign = F::campaign(F::segment());

        app(LaunchCampaign::class)->handle($campaign);

        expect(FakeChannel::sentTo('sms', $client->phone))->toHaveCount(1)
            ->and(FakeChannel::sentTo('sms', $client->phone)[0]['message']->body)->toContain('/c/'.CampaignMessage::first()->token)
            ->and(CampaignMessage::first()->channel)->toBe('sms');
    });
});

it('excludes clients without consent for a channel automatically', function () {
    asTenant($this->tenant, function () {
        $whatsappOnly = F::client(consents: ['whatsapp']);
        $smsOnly = F::client(consents: ['sms']);
        $none = F::client(consents: []);
        $emailOnly = F::client(consents: ['email']);

        app(LaunchCampaign::class)->handle(F::campaign(F::segment()));

        expect(FakeChannel::sentTo('whatsapp', $whatsappOnly->phone))->toHaveCount(1)
            ->and(FakeChannel::sentTo('whatsapp', $smsOnly->phone))->toHaveCount(0)
            ->and(FakeChannel::sentTo('sms', $smsOnly->phone))->toHaveCount(1)
            ->and(FakeChannel::sentTo('whatsapp', $none->phone))->toBeEmpty()
            ->and(FakeChannel::sentTo('sms', $none->phone))->toBeEmpty()
            ->and(FakeChannel::sentTo('sms', $emailOnly->phone))->toBeEmpty();
        expect(CampaignMessage::where('client_id', $none->id)->value('status'))->toBe('skipped_no_consent')
            ->and(CampaignMessage::where('client_id', $emailOnly->id)->value('status'))->toBe('skipped_no_consent');
    });
});

it('attributes revenue to bookings created from a campaign link', function () {
    [$campaign, $client, $token] = asTenant($this->tenant, function () {
        $client = F::client();
        $campaign = F::campaign(F::segment());
        app(LaunchCampaign::class)->handle($campaign);

        return [$campaign, $client, CampaignMessage::first()->token];
    });

    // /c/{token} → /book?campaign=ID
    $this->get(tenantUrl($this->tenant, 'c/'.$token))
        ->assertRedirectContains('/book?campaign='.$campaign->id);

    asTenant($this->tenant, function () use ($campaign, $client) {
        // Booking module creates the booking with campaign_id taken from ?campaign=
        $booking = F::booking($client, ['campaign_id' => $campaign->id, 'total' => 42000]);
        F::booking(F::client(), ['campaign_id' => null, 'total' => 99000]); // unrelated

        $message = CampaignMessage::first();
        expect($message->clicked_at)->not->toBeNull()
            ->and($message->status)->toBe('clicked')
            ->and($message->booking_id)->toBe($booking->id)
            ->and($message->revenue)->toBe(42000);

        $report = app(CampaignReport::class)->for($campaign->fresh());
        expect($report['clicked'])->toBe(1)->and($report['booked'])->toBe(1)->and($report['revenue'])->toBe(42000);

        // a cancellation removes the revenue
        $booking->update(['status' => BookingStatus::CancelledByClient]);
        expect(app(CampaignReport::class)->for($campaign->fresh())['revenue'])->toBe(0);
    });
});

it('fills campaign_id from the remembered click when the booking module did not', function () {
    [$campaign, $token] = asTenant($this->tenant, function () {
        F::client();
        $campaign = F::campaign(F::segment());
        app(LaunchCampaign::class)->handle($campaign);

        return [$campaign, CampaignMessage::first()->token];
    });

    $this->get(tenantUrl($this->tenant, 'c/'.$token));
    expect(session('marketing.attribution.campaign_id'))->toBe($campaign->id);
});

it('shows the campaign report page with the attributed revenue', function () {
    $campaign = asTenant($this->tenant, function () {
        $client = F::client();
        $campaign = F::campaign(F::segment());
        app(LaunchCampaign::class)->handle($campaign);
        F::booking($client, ['campaign_id' => $campaign->id, 'total' => 55500]);

        return $campaign;
    });

    $this->actingAs($this->tenant->owner)
        ->get(tenantUrl($this->tenant, 'dashboard/marketing/campaigns/'.$campaign->id))
        ->assertOk()
        ->assertSee(money(55500, 'QAR'));
});
