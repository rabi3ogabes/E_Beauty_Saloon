<?php

use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\AIStudio\Models\ClientLook;
use App\Domain\Branches\Models\Branch;
use App\Domain\CRM\Models\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../AIStudio/helpers.php';
require_once __DIR__.'/ApiHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Http::preventStrayRequests();
    aiProviders();
    $this->tenant = createTenant(slug: 'lumiere');
    $this->style = aiStyle($this->tenant);
    asTenant($this->tenant, fn () => $this->style->service->branches()->attach(Branch::first()->id, ['is_active' => true]));
    $this->client = aiClient($this->tenant);
    $this->h = apiHeaders($this->tenant, clientToken($this->client));
    aiCredits($this->tenant, 100);
});

function studioJob($test, array $extra = [])
{
    return $test->post(apiUrl('studio/jobs'), array_merge(['style_id' => $test->style->id, 'category' => 'hair', 'photo' => aiUpload()], $extra), $test->h);
}

it('lists the style library with the client quota and consent state', function () {
    $this->getJson(apiUrl('studio/styles?category=hair'), $this->h)->assertOk()
        ->assertJsonPath('data.0.name', 'Honey balayage')->assertJsonPath('data.0.service_id', $this->style->service_id)
        ->assertJsonPath('data.0.price.amount', 120000)->assertJsonPath('meta.consent_given', false)->assertJsonPath('meta.quota.remaining', 3);
    $this->app['auth']->forgetGuards();
    $this->getJson(apiUrl('studio/styles?category=nails'), apiHeaders($this->tenant))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.quota', null);
});

it('runs a try-on job from upload to before/after result and charges credits on success', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiImageResponse())]);

    studioJob($this)->assertStatus(422)->assertJsonPath('message', __('ai_studio.errors.consent_required'));

    $res = studioJob($this, ['consent' => '1', 'adult' => '1'])->assertStatus(202)
        ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.finished', true)
        ->assertJsonPath('data.service_id', $this->style->service_id)->assertJsonPath('data.price.amount', 120000)
        ->assertJsonPath('data.duration_minutes', 180)->assertJsonPath('data.book.service_id', $this->style->service_id);
    $lookId = $res->json('data.look_id');
    expect($lookId)->toStartWith('LK')->and($res->json('data.book.url'))->toContain('look='.$lookId);

    $poll = $this->getJson(apiUrl('studio/jobs/'.$res->json('data.id')), $this->h)->assertOk()->assertJsonPath('data.status', 'succeeded');
    $after = $poll->json('data.after_url');
    expect($after)->toStartWith(tenantUrl($this->tenant, 'api/v1/studio/looks/'.$lookId.'/media/after'));

    // Signed photo URL works on the salon host without headers; tampering fails.
    $this->get($after)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get(tenantUrl($this->tenant, 'api/v1/studio/looks/'.$lookId.'/media/after'))->assertForbidden();

    expect(asTenant($this->tenant, fn () => AIWallet::for($this->tenant)->balance))->toBeLessThan(100);
    $this->getJson(apiUrl('studio/styles'), $this->h)->assertJsonPath('meta.quota.remaining', 2)->assertJsonPath('meta.consent_given', true);
});

it('reports a failed job without charging credits', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'boom']], 500),
        'api.openai.com/*' => Http::response(['error' => ['message' => 'boom']], 500),
    ]);
    $this->postJson(apiUrl('studio/consent'), ['accept' => true, 'adult' => true], $this->h)->assertCreated()->assertJsonPath('data.minor', false);

    $res = studioJob($this)->assertStatus(202);
    expect($res->json('data.status'))->toBeIn(['failed', 'refunded'])->and($res->json('data.error'))->toBe(__('ai_studio.errors.try_again'))
        ->and($res->json('data.after_url'))->toBeNull()
        ->and(asTenant($this->tenant, fn () => AIWallet::for($this->tenant)->balance))->toBe(100);
});

it('validates category, minors and the salon wallet through the domain gates', function () {
    $this->postJson(apiUrl('studio/consent'), ['accept' => true, 'adult' => false], $this->h)->assertCreated()->assertJsonPath('data.minor', true);
    studioJob($this, ['category' => 'nails'])->assertStatus(422)->assertJsonPath('message', __('api.studio.style_category_mismatch'));
    studioJob($this)->assertStatus(422)->assertJsonPath('message', __('ai_studio.errors.minors_blocked'));

    $this->postJson(apiUrl('studio/consent'), ['accept' => true, 'adult' => true], $this->h)->assertCreated();
    asTenant($this->tenant, fn () => AIWallet::for($this->tenant)->forceFill(['balance' => 0])->save());
    studioJob($this)->assertStatus(422)->assertJsonPath('message', __('ai_studio.errors.salon_out_of_credits'));
    $this->post(apiUrl('studio/jobs'), ['style_id' => $this->style->id], $this->h)->assertStatus(422)->assertJsonValidationErrors('photo');
});

it('lists, saves and deletes my looks', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiImageResponse())]);
    $lookId = studioJob($this, ['consent' => '1', 'adult' => '1'])->assertStatus(202)->json('data.look_id');

    $this->getJson(apiUrl('looks'), $this->h)->assertOk()->assertJsonPath('data.0.id', $lookId)->assertJsonPath('data.0.saved', false)->assertJsonPath('meta.total', 1);
    $this->postJson(apiUrl("looks/{$lookId}/save"), [], $this->h)->assertOk()->assertJsonPath('data.saved', true)->assertJsonPath('data.expires_at', null);
    $this->getJson(apiUrl('looks?saved=1'), $this->h)->assertOk()->assertJsonCount(1, 'data');

    $look = asTenant($this->tenant, fn () => ClientLook::where('reference', $lookId)->first());
    $this->deleteJson(apiUrl("looks/{$lookId}"), [], $this->h)->assertNoContent();
    expect(asTenant($this->tenant, fn () => ClientLook::where('reference', $lookId)->exists()))->toBeFalse()
        ->and(Storage::disk('local')->exists($look->after_path))->toBeFalse();
});

it('keeps jobs and looks private to their owner', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiImageResponse())]);
    $res = studioJob($this, ['consent' => '1', 'adult' => '1'])->assertStatus(202);

    $other = asTenant($this->tenant, fn () => Client::create(['name' => 'Other', 'phone' => '+97455550999']));
    $this->app['auth']->forgetGuards();
    $h = apiHeaders($this->tenant, clientToken($other));
    $this->getJson(apiUrl('studio/jobs/'.$res->json('data.id')), $h)->assertNotFound();
    $this->postJson(apiUrl('looks/'.$res->json('data.look_id').'/save'), [], $h)->assertNotFound();
    $this->deleteJson(apiUrl('looks/'.$res->json('data.look_id')), [], $h)->assertNotFound();
    expect(asTenant($this->tenant, fn () => AIJob::count()))->toBe(1);
});

it('gates the studio API behind the ai_studio module', function () {
    withoutModule($this->tenant, 'ai_studio');
    $this->getJson(apiUrl('studio/styles'), $this->h)->assertForbidden()->assertJsonPath('module', 'ai_studio');
    $this->getJson(apiUrl('looks'), $this->h)->assertForbidden()->assertJsonPath('error.code', 'module_disabled');
});
