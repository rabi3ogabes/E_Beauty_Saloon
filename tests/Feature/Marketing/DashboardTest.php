<?php

use App\Domain\Marketing\Actions\InstallAutomationRecipes;
use App\Domain\Marketing\Models\Automation;
use App\Domain\Marketing\Models\Banner;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\MessageCreditWallet;
use App\Domain\Marketing\Models\TenantChannelSetting;
use App\Domain\Marketing\Models\WhatsappTemplate;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;
use App\Filament\Admin\Resources\MessageCredits\Pages\ManageMessageCreditWallets;
use App\Filament\Dashboard\Pages\Marketing\ChannelSettings;
use App\Filament\Dashboard\Pages\Marketing\LoyaltySettingsPage;
use App\Filament\Dashboard\Resources\Marketing\Automations\Pages\EditAutomationFlow;
use App\Filament\Dashboard\Resources\Marketing\Banners\Pages\BannerStudio;
use App\Filament\Dashboard\Resources\Marketing\Campaigns\Pages\CreateCampaign;
use App\Filament\Dashboard\Resources\Marketing\WhatsappTemplates\Pages\ManageWhatsappTemplates;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Marketing\MarketingFixtures as F;

const MARKETING_PAGES = [
    'dashboard/marketing/campaigns', 'dashboard/marketing/campaigns/create', 'dashboard/marketing/segments', 'dashboard/marketing/automations',
    'dashboard/marketing/banners', 'dashboard/marketing/banners/studio', 'dashboard/marketing/whatsapp-templates', 'dashboard/marketing/packages',
    'dashboard/marketing/client-packages', 'dashboard/marketing/memberships', 'dashboard/marketing/promotions', 'dashboard/marketing/gift-card-orders',
    'dashboard/marketing/channels',
];

const LOYALTY_PAGES = ['dashboard/marketing/loyalty', 'dashboard/marketing/referrals'];

beforeEach(fn () => MessagingManager::fake());

it('renders every marketing and loyalty dashboard page for the owner', function () {
    $tenant = createTenant();
    asTenant($tenant, function () {
        app(InstallAutomationRecipes::class)->handle();
        F::segment();
    });
    $automation = asTenant($tenant, fn () => Automation::first());

    foreach ([...MARKETING_PAGES, ...LOYALTY_PAGES, 'dashboard/marketing/reviews', 'dashboard/marketing/automations/'.$automation->id.'/flow'] as $path) {
        $this->actingAs($tenant->owner)->get(tenantUrl($tenant, $path))->assertOk();
    }
});

it('hides and blocks marketing pages when the module is disabled', function () {
    $signature = createTenant('signature'); // loyalty but no marketing
    foreach (MARKETING_PAGES as $path) {
        $this->actingAs($signature->owner)->get(tenantUrl($signature, $path))->assertForbidden();
    }
    foreach ([...LOYALTY_PAGES, 'dashboard/marketing/reviews'] as $path) {
        $this->actingAs($signature->owner)->get(tenantUrl($signature, $path))->assertOk();
    }
    $this->actingAs($signature->owner)->get(tenantUrl($signature, 'dashboard'))->assertOk()
        ->assertDontSee(__('marketing.campaigns.plural'))->assertSee(__('marketing.nav.loyalty'));
});

it('hides and blocks loyalty pages when neither module is subscribed', function () {
    $essential = createTenant('essential');
    foreach ([...LOYALTY_PAGES, 'dashboard/marketing/reviews', 'dashboard/marketing/campaigns'] as $path) {
        $this->actingAs($essential->owner)->get(tenantUrl($essential, $path))->assertForbidden();
    }
    $this->actingAs($essential->owner)->get(tenantUrl($essential, 'dashboard'))->assertOk()
        ->assertDontSee(__('marketing.campaigns.plural'))->assertDontSee(__('marketing.nav.loyalty'));
});

it('blocks staff without marketing permissions', function () {
    $tenant = createTenant();
    $this->actingAs(tenantUser($tenant, 'receptionist'))->get(tenantUrl($tenant, 'dashboard/marketing/campaigns'))->assertForbidden();
});

it('lets the marketing role manage campaigns', function () {
    $tenant = createTenant();
    $this->actingAs(tenantUser($tenant, 'marketing'))->get(tenantUrl($tenant, 'dashboard/marketing/campaigns'))->assertOk();
});

it('creates and sends a campaign from the wizard', function () {
    $tenant = createTenant();
    F::noQuietHours($tenant);
    $segment = asTenant($tenant, function () {
        F::client();
        F::client(consents: []);

        return F::segment();
    });

    $this->actingAs($tenant->owner);
    asTenant($tenant, function () use ($segment) {
        Filament::setCurrentPanel('dashboard');
        Livewire::test(CreateCampaign::class)
            ->fillForm([
                'name' => 'Ramadan glow', 'segment_id' => $segment->id, 'primary_channel' => 'whatsapp', 'fallback_channel' => 'sms',
                'content' => ['en' => ['body' => 'Hi {first_name} {link}']], 'when' => 'now',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $campaign = Campaign::firstOrFail();
        expect($campaign->status)->toBe('sent')->and($campaign->channels)->toBe(['whatsapp', 'sms'])
            ->and(FakeChannel::sentTo('whatsapp'))->toHaveCount(1);
    });
});

it('saves channel settings without exposing stored secrets', function () {
    $tenant = createTenant();
    $this->actingAs($tenant->owner);
    asTenant($tenant, function () {
        Filament::setCurrentPanel('dashboard');
        Livewire::test(ChannelSettings::class)
            ->set('data.channels.whatsapp.is_active', true)
            ->set('data.channels.whatsapp.mode', 'own')
            ->set('data.channels.whatsapp.provider', 'meta')
            ->set('data.channels.whatsapp.phone_number_id', 'PN-9')
            ->set('data.channels.whatsapp.token', 'secret-token')
            ->set('data.quiet.start', '22:00')
            ->call('save')
            ->assertHasNoErrors();

        $setting = TenantChannelSetting::where('channel', 'whatsapp')->first();
        expect($setting->credential('token'))->toBe('secret-token')->and($setting->external_id)->toBe('PN-9')
            ->and(tenant()->fresh()->setting('marketing.quiet_hours.start'))->toBe('22:00');

        Livewire::test(ChannelSettings::class)->assertSet('data.channels.whatsapp.token', null)->call('save');
        expect(TenantChannelSetting::where('channel', 'whatsapp')->first()->credential('token'))->toBe('secret-token');
    });
});

it('syncs WhatsApp template approval status from Meta', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
        ['name' => 'spring_glow', 'language' => 'en', 'status' => 'APPROVED', 'id' => '111'],
        ['name' => 'eid_offer', 'language' => 'ar', 'status' => 'REJECTED', 'id' => '222', 'rejected_reason' => 'PROMOTIONAL'],
    ]])]);
    $tenant = createTenant();
    $this->actingAs($tenant->owner);

    asTenant($tenant, function () {
        Filament::setCurrentPanel('dashboard');
        TenantChannelSetting::create(['channel' => 'whatsapp', 'provider' => 'meta', 'credentials' => ['phone_number_id' => 'PN', 'token' => 't', 'waba_id' => 'WABA1']]);
        WhatsappTemplate::create(['name' => 'spring_glow', 'language' => 'en', 'body' => 'Hi {{1}}', 'status' => 'pending']);
        WhatsappTemplate::create(['name' => 'eid_offer', 'language' => 'ar', 'body' => 'مرحبا {{1}}', 'status' => 'pending']);

        Livewire::test(ManageWhatsappTemplates::class)->callAction('sync');

        expect(WhatsappTemplate::where('name', 'spring_glow')->value('status'))->toBe('approved')
            ->and(WhatsappTemplate::where('name', 'eid_offer')->value('status'))->toBe('rejected')
            ->and(WhatsappTemplate::where('name', 'eid_offer')->value('rejection_reason'))->toBe('PROMOTIONAL');
    });
    Http::assertSent(fn ($request) => str_contains($request->url(), 'WABA1/message_templates'));
});

it('saves an automation flow from the builder', function () {
    $tenant = createTenant();
    $this->actingAs($tenant->owner);
    asTenant($tenant, function () {
        Filament::setCurrentPanel('dashboard');
        $automation = Automation::create(['name' => 'Flow', 'trigger' => 'birthday', 'steps' => [['type' => 'send_message', 'body' => ['en' => 'Hi']]]]);

        Livewire::test(EditAutomationFlow::class, ['record' => $automation->id])
            ->set('steps', [
                ['type' => 'wait', 'days' => 1, 'hours' => 0, 'minutes' => 0],
                ['type' => 'condition', 'check' => 'has_consent', 'value' => 'whatsapp'],
                ['type' => 'send_message', 'channels' => ['whatsapp', 'email'], 'body' => ['en' => 'Happy birthday {first_name}', 'ar' => 'عيد ميلاد سعيد'], 'template' => '', 'link' => '', 'transactional' => false],
                ['type' => 'award_points', 'points' => 150],
            ])
            ->set('isActive', true)
            ->call('save')
            ->assertHasNoErrors();

        $automation->refresh();
        expect($automation->is_active)->toBeTrue()
            ->and($automation->steps[0])->toBe(['type' => 'wait', 'days' => 1])
            ->and($automation->steps[2]['channels'])->toBe(['whatsapp', 'email'])
            ->and($automation->steps[3])->toBe(['type' => 'award_points', 'points' => 150]);
    });
});

it('saves a banner with its exported PNG and generates AI copy', function () {
    Storage::fake('public');
    $tenant = createTenant();
    $this->actingAs($tenant->owner);
    asTenant($tenant, function () {
        Filament::setCurrentPanel('dashboard');
        Livewire::test(BannerStudio::class)
            ->set('name', 'Golden hour')
            ->set('png', UploadedFile::fake()->image('banner.png', 1080, 1080))
            ->call('saveBanner', 'post', 'editorial', ['background' => ['type' => 'solid', 'color' => '#FAF7F2'], 'elements' => [['id' => 'a', 'type' => 'text', 'text' => 'Hello']]])
            ->assertHasNoErrors()
            ->call('generateWithAi', 'champagne silk', 'post');

        $banner = Banner::firstOrFail();
        expect($banner->format)->toBe('post')->and($banner->exported_path)->toStartWith('banners/'.tenant()->id.'/');
        Storage::disk('public')->assertExists($banner->exported_path);
    });
});

it('lets the super admin top up a salon’s message credits', function () {
    $tenant = createTenant();
    $this->actingAs(platformAdmin());
    Filament::setCurrentPanel('admin');

    $this->get(centralUrl('admin/message-prices'))->assertOk();
    $this->get(centralUrl('admin/message-credits'))->assertOk();

    Livewire::test(ManageMessageCreditWallets::class)
        ->callAction('topupTenant', data: ['tenant_id' => $tenant->id, 'amount' => '150', 'note' => 'Welcome pack'])
        ->assertHasNoActionErrors();

    expect(MessageCreditWallet::where('tenant_id', $tenant->id)->value('balance'))->toBe(15000);
});

it('saves loyalty settings', function () {
    $tenant = createTenant();
    $this->actingAs($tenant->owner);
    asTenant($tenant, function () {
        Filament::setCurrentPanel('dashboard');
        Livewire::test(LoyaltySettingsPage::class)
            ->set('data.points_per_currency_unit', 2)
            ->set('data.point_value', 0.1)
            ->set('data.referrer_points', 800)
            ->call('save')
            ->assertHasNoErrors();

        $settings = tenant()->fresh();
        expect($settings->setting('loyalty.points_per_currency_unit'))->toEqual(2)
            ->and($settings->setting('loyalty.point_value'))->toBe(10)
            ->and($settings->setting('loyalty.referral.referrer_points'))->toBe(800)
            ->and(count($settings->setting('loyalty.tiers')))->toBe(3);
    });
});
