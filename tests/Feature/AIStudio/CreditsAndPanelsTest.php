<?php

use App\Domain\AIStudio\Actions\ApplyCreditInvoice;
use App\Domain\AIStudio\Actions\CreditLedger;
use App\Domain\AIStudio\Actions\GrantMonthlyCredits;
use App\Domain\AIStudio\Actions\PurchaseCreditPack;
use App\Domain\AIStudio\Actions\RequestConsultation;
use App\Domain\AIStudio\AIMarketingContentGenerator;
use App\Domain\AIStudio\Contracts\GeneratesMarketingContent;
use App\Domain\AIStudio\Models\AICreditPack;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\AIProvider;
use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\Billing\Actions\MarkInvoicePaid;
use App\Filament\Dashboard\Pages\AIStudio\AIStudioSettings;
use App\Filament\Dashboard\Pages\AIStudio\AIStudioWallet;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Http::preventStrayRequests();
});

it('credits the wallet when a credits invoice is paid, exactly once', function () {
    $tenant = createTenant();
    $pack = AICreditPack::create(['name' => ['en' => 'Studio 500'], 'credits' => 500, 'price' => 49900]);

    $invoice = asTenant($tenant, fn () => app(PurchaseCreditPack::class)->handle($tenant, $pack));
    expect($invoice->type)->toBe('credits')->and($invoice->total)->toBe(49900)
        ->and(asTenant($tenant, fn () => AIWallet::for($tenant)->balance))->toBe(0);

    app(MarkInvoicePaid::class)->handle($invoice, 'manual', 'TEST');
    app(ApplyCreditInvoice::class)->handle($invoice->fresh()); // replay is idempotent

    expect(asTenant($tenant, fn () => AIWallet::for($tenant)->balance))->toBe(500);
});

it('grants monthly included credits from the plan once per month', function () {
    $tenant = createTenant('maison'); // 1000 included credits

    app(GrantMonthlyCredits::class)->handle();
    app(GrantMonthlyCredits::class)->handle();

    expect(asTenant($tenant, fn () => AIWallet::for($tenant)->balance))->toBe(1000);
    $this->travel(1)->months();
    app(GrantMonthlyCredits::class)->handle();
    expect(asTenant($tenant, fn () => AIWallet::for($tenant)->fresh()->balance))->toBe(2000);
});

it('notifies the owner and auto tops up when credits run low', function () {
    $tenant = createTenant();
    $pack = AICreditPack::create(['name' => ['en' => 'Top-up'], 'credits' => 200, 'price' => 19900]);
    asTenant($tenant, function () use ($tenant, $pack) {
        AIWallet::for($tenant)->update(['low_balance_threshold' => 50, 'auto_topup' => true, 'auto_topup_pack_id' => $pack->id]);
        $ledger = app(CreditLedger::class);
        $ledger->credit($tenant, 60, 'adjustment', 'seed');
        $ledger->debit($tenant, 20, 'charge', 'c1');
        $ledger->debit($tenant, 1, 'charge', 'c2');
    });

    expect($tenant->invoices()->where('type', 'credits')->count())->toBe(1)
        ->and($tenant->owner->notifications()->count())->toBe(1);
});

it('asks Claude for the consultation over the Messages API', function () {
    aiProviders();
    $tenant = createTenant();
    $style = aiStyle($tenant);
    $client = aiClient($tenant);
    aiCredits($tenant);
    aiConsent($tenant, $client);
    Http::fake(['api.anthropic.com/v1/messages' => Http::sequence()
        ->push(['content' => [['type' => 'text', 'text' => '{"face_shape":"oval","skin_tone":"medium","undertone":"warm","hair_texture":"wavy","notes":"Glowing skin"}']], 'stop_reason' => 'end_turn'])
        ->push(['content' => [['type' => 'text', 'text' => '[{"style_id":'.$style->id.',"reason":"Warm honey tones light up your skin."}]']], 'stop_reason' => 'end_turn']),
    ]);

    $job = asTenant($tenant, fn () => app(RequestConsultation::class)->handle($tenant, aiPng(), $client, null, 'en'));

    $job = asTenant($tenant, fn () => AIJob::find($job->id));
    expect($job->status)->toBe('succeeded')->and($job->provider_driver)->toBe('anthropic')
        ->and($job->output_refs['analysis']['undertone'])->toBe('warm')
        ->and($job->output_refs['looks'][0]['style_id'])->toBe($style->id);

    Http::assertSent(function ($r) {
        $body = $r->data();

        return $r->url() === 'https://api.anthropic.com/v1/messages'
            && $r->hasHeader('x-api-key', 'a-key')
            && $r->hasHeader('anthropic-version', '2023-06-01')
            && $body['model'] === 'claude-opus-5-5'
            && $body['messages'][0]['content'][0]['type'] === 'image'
            && $body['messages'][0]['content'][0]['source']['type'] === 'base64';
    });

    $this->actingAs($client, 'client')->get(tenantUrl($tenant, 'studio/consultations/'.$job->id))
        ->assertOk()->assertSee('Warm honey tones light up your skin.')->assertSee('/book?branch=main&amp;service=balayage', false);
});

it('binds the real marketing content generator and charges credits', function () {
    aiProviders();
    $tenant = createTenant();
    aiCredits($tenant, 50);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiImageResponse()),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Glow, refined.']], 'stop_reason' => 'end_turn']),
    ]);

    $generator = app(GeneratesMarketingContent::class);
    expect($generator)->toBeInstanceOf(AIMarketingContentGenerator::class);

    asTenant($tenant, function () use ($generator, $tenant) {
        $banner = $generator->bannerVisual('Eid glow campaign', '16:9');
        expect($banner['error'])->toBeNull()->and($banner['credits_used'])->toBe(6)->and($banner['image_url'])->toContain('ai-studio/marketing');
        expect($generator->copy('Eid offer on balayage'))->toBe(['en' => 'Glow, refined.', 'ar' => 'Glow, refined.']);
        expect(AIWallet::for($tenant)->balance)->toBe(50 - 6 - 1);
    });
});

it('renders the AI admin pages for the platform owner', function () {
    aiProviders();
    $this->actingAs(platformAdmin());
    foreach (['admin/ai-providers', 'admin/ai-task-prices', 'admin/ai-credit-packs', 'admin/ai-jobs', 'admin/ai-margin'] as $path) {
        $this->get(centralUrl($path))->assertOk();
    }
});

it('renders the AI Studio dashboard pages and saves studio settings', function () {
    $tenant = createTenant();
    $owner = $tenant->owner;
    aiStyle($tenant);
    AICreditPack::create(['name' => ['en' => 'Studio 500'], 'credits' => 500, 'price' => 49900]);

    foreach (['dashboard/ai-styles', 'dashboard/ai-studio/credits', 'dashboard/ai-studio/settings'] as $path) {
        $this->actingAs($owner)->get(tenantUrl($tenant, $path))->assertOk();
    }

    asTenant($tenant);
    Filament::setCurrentPanel('dashboard');
    $this->actingAs($owner);
    Livewire::test(AIStudioSettings::class)
        ->set('data.free_tries_per_client', 5)
        ->set('data.byok_enabled', true)
        ->set('data.byok.openai', 'sk-salon')
        ->call('save')
        ->assertHasNoErrors();

    expect($tenant->fresh()->setting('ai.free_tries_per_client'))->toBe(5)
        ->and(AIProvider::where('tenant_id', $tenant->id)->first()->key())->toBe('sk-salon');

    Livewire::test(AIStudioWallet::class)->call('buy', AICreditPack::first()->id);
    expect($tenant->invoices()->where('type', 'credits')->count())->toBe(1);
});

it('hides AI Studio from the dashboard when the module is disabled', function () {
    $tenant = createTenant('essential');
    $this->actingAs($tenant->owner)->get(tenantUrl($tenant, 'dashboard/ai-styles'))->assertForbidden();
});
