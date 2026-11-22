<?php

use App\Domain\AIStudio\Actions\AIMarginReport;
use App\Domain\AIStudio\Actions\CreditLedger;
use App\Domain\AIStudio\Actions\PurgeExpiredLooks;
use App\Domain\AIStudio\Contracts\ModeratesContent;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\AIProvider;
use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\AIStudio\Models\AIWalletTransaction;
use App\Domain\AIStudio\Models\ClientLook;
use App\Domain\AIStudio\Models\Style;
use App\Domain\AIStudio\Support\AIRouter;
use App\Domain\AIStudio\Support\LookPresenter;
use App\Domain\AIStudio\Support\PhotoVault;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Http::preventStrayRequests();
    $this->providers = aiProviders();
    $this->tenant = createTenant(slug: 'lumiere');
    $this->style = aiStyle($this->tenant);
    $this->client = aiClient($this->tenant);
});

function tryOn($test, ?int $styleId = null)
{
    return $test->actingAs($test->client, 'client')->postJson(tenantUrl($test->tenant, 'studio/try-on'), [
        'style_id' => $styleId ?? $test->style->id,
        'photo' => aiUpload(),
    ]);
}

it('falls back to provider B when provider A times out', function () {
    aiCredits($this->tenant);
    aiConsent($this->tenant, $this->client);
    Http::fake([
        'generativelanguage.googleapis.com/*' => fn () => throw new ConnectionException('Operation timed out after 5000 milliseconds'),
        'api.openai.com/v1/images/edits' => Http::response(openaiImageResponse()),
    ]);

    $res = tryOn($this)->assertStatus(202);

    $job = asTenant($this->tenant, fn () => AIJob::findOrFail($res->json('id')));
    expect($job->status)->toBe('succeeded')
        ->and($job->provider_driver)->toBe('openai')
        ->and($job->attempt_log[0])->toMatchArray(['driver' => 'gemini', 'ok' => false, 'error' => 'timeout'])
        ->and($job->attempt_log[1])->toMatchArray(['driver' => 'openai', 'ok' => true])
        ->and($this->providers['gemini']->fresh()->health_status)->toBe('degraded')
        ->and($this->providers['openai']->fresh()->latency_ms_ema)->not->toBeNull();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.openai.com/v1/images/edits') && $r->hasHeader('Authorization', 'Bearer o-key'));
    expect($res->json('redirect'))->toContain('/studio/looks/LK');
});

it('routes by priority and skips providers that are down', function () {
    $this->providers['gemini']->forceFill(['health_status' => 'down', 'last_failure_at' => now()])->save();
    $router = app(AIRouter::class);

    expect($router->candidates('hair_tryon', 'image', $this->tenant->id)->pluck('driver')->all())->toBe(['openai'])
        ->and($router->candidates('consultant', 'text', $this->tenant->id)->pluck('driver')->first())->toBe('anthropic');

    // Bring-your-own-key rows of the tenant are preferred.
    AIProvider::create(['tenant_id' => $this->tenant->id, 'driver' => 'openai', 'name' => 'Salon OpenAI', 'api_key' => 'mine', 'priority' => 9]);
    expect($router->candidates('hair_tryon', 'image', $this->tenant->id)->first()->name)->toBe('Salon OpenAI');
    expect($router->candidates('hair_tryon', 'image', null)->pluck('name')->all())->not->toContain('Salon OpenAI');
});

it('deducts credits only on a successful result', function () {
    aiCredits($this->tenant, 100);
    aiConsent($this->tenant, $this->client);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiImageResponse())]);

    tryOn($this)->assertStatus(202);

    $wallet = asTenant($this->tenant, fn () => AIWallet::for($this->tenant));
    expect($wallet->balance)->toBe(96);
    $look = asTenant($this->tenant, fn () => ClientLook::first());
    expect($look->after_path)->not->toBeNull()
        ->and(Storage::disk('local')->get($look->after_path))->not->toContain('PNG') // encrypted at rest
        ->and(app(PhotoVault::class)->get($look->after_path))->toStartWith("\x89PNG");
});

it('charges nothing when every provider fails', function () {
    aiCredits($this->tenant, 100);
    aiConsent($this->tenant, $this->client);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => 'overloaded'], 503),
        'api.openai.com/*' => Http::response(['error' => 'bad'], 500),
    ]);

    $res = tryOn($this)->assertStatus(202);

    $job = asTenant($this->tenant, fn () => AIJob::find($res->json('id')));
    expect($job->status)->toBe('failed')->and($job->charged_credits)->toBe(0)->and($job->attempt_log)->toHaveCount(2)
        ->and(asTenant($this->tenant, fn () => AIWallet::for($this->tenant)->balance))->toBe(100);
    $this->getJson(tenantUrl($this->tenant, 'studio/jobs/'.$job->id))->assertOk()->assertJsonPath('status', 'failed');
});

it('refunds credits when the job fails after the charge', function () {
    aiCredits($this->tenant, 100);
    aiConsent($this->tenant, $this->client);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiImageResponse())]);
    // Output moderation rejects the generated image.
    app()->instance(ModeratesContent::class, new class implements ModeratesContent
    {
        public function check(string $image, string $stage): ?string
        {
            return $stage === 'output' ? 'Rejected by moderation' : null;
        }
    });

    $res = tryOn($this)->assertStatus(202);

    $job = asTenant($this->tenant, fn () => AIJob::find($res->json('id')));
    expect($job->status)->toBe('refunded')->and($job->charged_credits)->toBe(0)
        ->and(asTenant($this->tenant, fn () => AIWallet::for($this->tenant)->balance))->toBe(100)
        ->and(asTenant($this->tenant, fn () => AIWalletTransaction::orderBy('id')->pluck('type')->all()))->toBe(['adjustment', 'charge', 'refund']);
});

it('hard-stops when the salon wallet is empty', function () {
    aiConsent($this->tenant, $this->client);
    Http::fake();

    tryOn($this)->assertStatus(422)->assertJsonPath('message', __('ai_studio.errors.salon_out_of_credits'));
    Http::assertNothingSent();
});

it('opens booking prefilled with the mapped service from "Book this look"', function () {
    aiCredits($this->tenant);
    aiConsent($this->tenant, $this->client);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiImageResponse())]);
    tryOn($this);
    $look = asTenant($this->tenant, fn () => ClientLook::first());

    $page = $this->actingAs($this->client, 'client')->get(tenantUrl($this->tenant, 'studio/looks/'.$look->reference))->assertOk();

    $expected = '/book?branch=main&amp;service=balayage&amp;look='.$look->reference;
    $page->assertSee($expected, false)->assertSee(__('ai_studio.look.book'))->assertSee(money(120000));
});

it('requires consent before upload', function () {
    aiCredits($this->tenant);
    Http::fake();

    tryOn($this)->assertStatus(422)->assertJsonPath('message', __('ai_studio.errors.consent_required'));
    expect(asTenant($this->tenant, fn () => AIJob::count()))->toBe(0);

    $this->actingAs($this->client, 'client')->postJson(tenantUrl($this->tenant, 'studio/consent'), ['accept' => '1', 'adult' => 'yes'])->assertOk();
    expect($this->client->fresh()->hasConsent('ai_photos'))->toBeTrue();
});

it('blocks minors from face features but allows nails', function () {
    aiCredits($this->tenant);
    aiConsent($this->tenant, $this->client, adult: false);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiImageResponse())]);

    tryOn($this)->assertStatus(422)->assertJsonPath('message', __('ai_studio.errors.minors_blocked'));

    $nails = asTenant($this->tenant, fn () => Style::create([
        'category' => 'nails', 'name' => ['en' => 'Rouge noir'], 'prompt' => 'Glossy rouge noir almond nails', 'task' => 'nails_tryon',
    ]));
    tryOn($this, $nails->id)->assertStatus(202);
});

it('auto-deletes unsaved looks after the retention window', function () {
    aiCredits($this->tenant);
    aiConsent($this->tenant, $this->client);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiImageResponse())]);
    tryOn($this);
    tryOn($this);
    [$expired, $saved] = asTenant($this->tenant, fn () => ClientLook::orderBy('id')->get()->all());
    $saved->update(['saved' => true, 'expires_at' => null]);
    $this->travel(8)->days();

    $count = app(PurgeExpiredLooks::class)->handle();

    expect($count)->toBeGreaterThanOrEqual(1)
        ->and(Storage::disk('local')->exists($expired->before_path))->toBeFalse()
        ->and(Storage::disk('local')->exists($expired->after_path))->toBeFalse()
        ->and(ClientLook::withoutGlobalScopes()->find($expired->id))->toBeNull()
        ->and(Storage::disk('local')->exists($saved->after_path))->toBeTrue();
});

it('reports margin per provider', function () {
    asTenant($this->tenant, function () {
        foreach ([[$this->providers['gemini'], 4, 4], [$this->providers['gemini'], 8, 4], [$this->providers['openai'], 4, 6]] as [$p, $credits, $cost]) {
            AIJob::create(['task' => 'hair_tryon', 'status' => 'succeeded', 'ai_provider_id' => $p->id, 'provider_driver' => $p->driver, 'cost_credits' => $credits, 'charged_credits' => $credits, 'provider_cost' => $cost]);
        }
        AIJob::create(['task' => 'hair_tryon', 'status' => 'failed', 'ai_provider_id' => $this->providers['openai']->id, 'provider_driver' => 'openai', 'cost_credits' => 4, 'provider_cost' => 0]);
    });
    config(['ai.credit_value_minor' => 10, 'ai.usd_to_platform_rate' => 3.64]);

    $report = collect(app(AIMarginReport::class)->handle())->keyBy('driver');

    expect($report['gemini'])->toMatchArray(['jobs' => 2, 'credits' => 12, 'revenue' => 120, 'cost' => 29, 'margin' => 91])
        ->and($report['openai'])->toMatchArray(['jobs' => 1, 'credits' => 4, 'revenue' => 40, 'cost' => 22, 'margin' => 18]);

    $this->actingAs(platformAdmin())->get(centralUrl('admin/ai-margin'))->assertOk()->assertSee('Gemini');
});

it('returns 403 on /studio when the module is disabled', function () {
    $essential = createTenant('essential');

    $this->get(tenantUrl($essential, 'studio'))->assertForbidden();
    $this->get(tenantUrl($this->tenant, 'studio'))->assertOk()->assertSee(__('ai_studio.headline'));
});

it('keeps looks inside their own salon and owner', function () {
    aiCredits($this->tenant);
    aiConsent($this->tenant, $this->client);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiImageResponse())]);
    tryOn($this);
    $look = asTenant($this->tenant, fn () => ClientLook::first());

    $other = createTenant();
    $this->get(tenantUrl($other, 'studio/looks/'.$look->reference))->assertNotFound();
    $stranger = aiClient($other);
    $this->actingAs($stranger, 'client')->get(tenantUrl($this->tenant, 'studio/looks/'.$look->reference))->assertForbidden();
});

it('saves, shares with a watermark, exports and erases a client look', function () {
    aiCredits($this->tenant);
    aiConsent($this->tenant, $this->client);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiImageResponse())]);
    tryOn($this);
    $look = asTenant($this->tenant, fn () => ClientLook::first());

    $this->actingAs($this->client, 'client')->post(tenantUrl($this->tenant, 'studio/looks/'.$look->reference.'/save'))->assertRedirect();
    expect($look->fresh()->saved)->toBeTrue()->and($look->fresh()->expires_at)->toBeNull();

    URL::forceRootUrl('http://lumiere.salon.test');
    $shareUrl = asTenant($this->tenant, fn () => LookPresenter::shareUrl($look));
    URL::forceRootUrl(null);
    $share = $this->get($shareUrl);
    $share->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect(substr($share->getContent(), 0, 2))->toBe("\xFF\xD8");

    $this->get(tenantUrl($this->tenant, 'account/looks'))->assertOk()->assertSee($look->style->name);
    $this->get(tenantUrl($this->tenant, 'studio/my-data'))->assertOk()->assertJsonPath('looks.0.reference', $look->reference);

    $this->post(tenantUrl($this->tenant, 'studio/my-data/erase'))->assertRedirect();
    expect(ClientLook::withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($look->after_path))->toBeFalse()
        ->and(AIJob::withoutGlobalScopes()->first()->client_id)->toBeNull();
});

it('lets guests in kiosk mode create looks with a device fingerprint', function () {
    aiCredits($this->tenant);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiImageResponse())]);

    $this->get(tenantUrl($this->tenant, 'studio/kiosk'))->assertOk();
    $this->postJson(tenantUrl($this->tenant, 'studio/consent'), ['accept' => '1', 'adult' => 'yes'])->assertOk();
    $res = $this->postJson(tenantUrl($this->tenant, 'studio/try-on'), ['style_id' => $this->style->id, 'photo' => aiUpload()])->assertStatus(202);

    $job = asTenant($this->tenant, fn () => AIJob::find($res->json('id')));
    expect($job->source)->toBe('kiosk')->and($job->client_id)->toBeNull()->and($job->device_fingerprint)->not->toBeNull();
    $this->get(tenantUrl($this->tenant, 'studio/looks/'.$job->look->reference))->assertOk();
});

it('limits free tries per client per month', function () {
    aiCredits($this->tenant, 500);
    aiConsent($this->tenant, $this->client);
    $settings = $this->tenant->settings;
    $settings['ai']['free_tries_per_client'] = 1;
    $this->tenant->update(['settings' => $settings]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiImageResponse())]);

    tryOn($this)->assertStatus(202);
    tryOn($this)->assertStatus(422)->assertJsonPath('message', __('ai_studio.errors.quota_exhausted'));
});

it('ledger is idempotent and blocks overdrafts', function () {
    $ledger = app(CreditLedger::class);
    asTenant($this->tenant, function () use ($ledger) {
        $ledger->credit($this->tenant, 10, 'adjustment', 'k1');
        $ledger->credit($this->tenant, 10, 'adjustment', 'k1');
        expect($ledger->balance($this->tenant))->toBe(10);
        expect(fn () => $ledger->debit($this->tenant, 11, 'charge', 'k2'))->toThrow(DomainException::class);
    });
});
