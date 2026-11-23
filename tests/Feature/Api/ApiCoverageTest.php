<?php

use App\Domain\AIStudio\Actions\RecordConsent;
use App\Domain\Api\Models\ClientDevice;
use App\Domain\CRM\Models\Client;
use App\Domain\Messaging\MessagingManager;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/ApiHelpers.php';

beforeEach(function () {
    MessagingManager::fake();
    $this->tenant = createTenant(slug: 'lumiere');
    $this->client = asTenant($this->tenant, fn () => Client::create(['name' => 'Reem', 'phone' => '+97455550001']));
    $this->h = apiHeaders($this->tenant, clientToken($this->client));
});

it('registers every Phase 10 endpoint under /api/v1 with an app guard or as public', function () {
    $public = ['api.v1.availability', 'api.v1.studio.styles', 'api.v1.studio.media', 'api.v1.packages.index', 'api.v1.staff.auth.login', 'api.v1.owner.auth.login'];
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'api.v1.'))
        ->filter(fn ($r) => preg_match('#Api\\\\V1\\\\(Booking|Studio|Marketing|StaffApp|OwnerApp|TeamAuth)Controller#', $r->getActionName()));

    expect($routes->count())->toBeGreaterThan(35);
    foreach ($routes as $route) {
        $guarded = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'api.actor:'));
        expect($guarded || in_array($route->getName(), $public, true))->toBeTrue($route->getName().' has no api.actor guard');
    }
});

it('withdraws AI consent from the app', function () {
    asTenant($this->tenant, fn () => app(RecordConsent::class)->handle($this->client, null, true));
    $this->deleteJson(apiUrl('studio/consent'), [], $this->h)->assertNoContent();
    expect(asTenant($this->tenant, fn () => RecordConsent::current($this->client, null)))->toBeNull();
});

it('gates referral info behind the loyalty module', function () {
    $this->getJson(apiUrl('me/referral'), $this->h)->assertOk()->assertJsonPath('data.code', $this->client->referral_code);
    withoutModule($this->tenant, 'loyalty');
    $this->getJson(apiUrl('me/referral'), $this->h)->assertForbidden()->assertJsonPath('module', 'loyalty');
});

it('only removes the signed-in client\'s own device tokens', function () {
    $other = asTenant($this->tenant, fn () => Client::create(['name' => 'Other', 'phone' => '+97455550002']));
    asTenant($this->tenant, fn () => ClientDevice::create(['client_id' => $other->id, 'token' => 'theirs', 'platform' => 'ios', 'last_seen_at' => now()]));

    $this->deleteJson(apiUrl('devices/theirs'), [], $this->h)->assertNoContent();
    expect(asTenant($this->tenant, fn () => ClientDevice::where('token', 'theirs')->exists()))->toBeTrue();
});

it('serves the owner profile to owner tokens', function () {
    $this->app['auth']->forgetGuards();
    $this->getJson(apiUrl('owner/me'), apiHeaders($this->tenant, teamToken($this->tenant->owner, 'owner')))
        ->assertOk()->assertJsonPath('data.is_owner', true)->assertJsonPath('data.salon.slug', 'lumiere');
});
