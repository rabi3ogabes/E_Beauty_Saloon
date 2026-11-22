<?php

use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\CRM\Models\Client;
use App\Domain\Messaging\MessagingManager;

beforeEach(fn () => MessagingManager::fake());

it('exposes the salon catalog by X-Tenant header with ETag caching', function () {
    $tenant = createTenant(slug: 'lumiere');
    asTenant($tenant, function () {
        $cat = ServiceCategory::create(['name' => ['en' => 'Hair', 'ar' => 'الشعر'], 'slug' => 'hair']);
        Service::create(['service_category_id' => $cat->id, 'name' => ['en' => 'Blow-dry', 'ar' => 'استشوار'], 'slug' => 'blow-dry', 'price' => 15000, 'duration_minutes' => 45]);
    });

    $r = $this->getJson(centralUrl('api/v1/services'), ['X-Tenant' => 'lumiere'])
        ->assertOk()->assertJsonPath('data.0.price.amount', 15000)->assertJsonPath('data.0.duration_minutes', 45);

    $this->getJson(centralUrl('api/v1/services'), ['X-Tenant' => 'lumiere', 'If-None-Match' => $r->headers->get('ETag')])->assertStatus(304);
    $this->getJson(centralUrl('api/v1/salon'), ['X-Tenant' => 'lumiere'])->assertOk()->assertJsonPath('data.slug', 'lumiere');
    $this->getJson(centralUrl('api/v1/salon'))->assertNotFound();
});

it('issues a Sanctum token after phone OTP and serves the profile', function () {
    $tenant = createTenant(slug: 'lumiere');
    $h = ['X-Tenant' => 'lumiere'];

    $this->postJson(centralUrl('api/v1/auth/otp'), ['phone' => '55551234'], $h)->assertAccepted();
    $token = $this->postJson(centralUrl('api/v1/auth/verify'), ['phone' => '+97455551234', 'code' => '123456', 'device_name' => 'iPhone', 'name' => 'Reem'], $h)
        ->assertOk()->json('token');

    $this->getJson(centralUrl('api/v1/me'), $h + ['Authorization' => "Bearer $token"])->assertOk()->assertJsonPath('data.name', 'Reem');
    $this->patchJson(centralUrl('api/v1/me'), ['consents' => ['sms' => true]], $h + ['Authorization' => "Bearer $token"])
        ->assertOk()->assertJsonPath('data.consents.sms', true);
});

it('rejects a token from another salon', function () {
    createTenant(slug: 'alpha');
    $b = createTenant(slug: 'beta');
    $client = asTenant($b, fn () => Client::create(['name' => 'B', 'phone' => '+97455550001']));
    $token = $client->createToken('x')->plainTextToken;

    $this->getJson(centralUrl('api/v1/me'), ['X-Tenant' => 'beta', 'Authorization' => "Bearer $token"])->assertOk();
    $this->app['auth']->forgetGuards();
    $this->getJson(centralUrl('api/v1/me'), ['X-Tenant' => 'alpha', 'Authorization' => "Bearer $token"])->assertUnauthorized();
});

it('exports and anonymises client data on request', function () {
    $tenant = createTenant(slug: 'lumiere');
    $client = asTenant($tenant, fn () => Client::create(['name' => 'Delete Me', 'phone' => '+97455550002', 'email' => 'd@example.com']));
    $token = $client->createToken('x')->plainTextToken;
    $h = ['X-Tenant' => 'lumiere', 'Authorization' => "Bearer $token"];

    $this->getJson(centralUrl('api/v1/me/export'), $h)->assertOk()->assertJsonPath('profile.email', 'd@example.com');
    $this->deleteJson(centralUrl('api/v1/me'), [], $h)->assertNoContent();

    $fresh = asTenant($tenant, fn () => Client::withTrashed()->find($client->id));
    expect($fresh->email)->toBeNull()->and($fresh->name)->toBe('Deleted client')->and($fresh->trashed())->toBeTrue();
});
