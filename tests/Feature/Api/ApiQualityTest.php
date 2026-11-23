<?php

use App\Domain\CRM\Models\Client;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;

require_once __DIR__.'/ApiHelpers.php';

beforeEach(function () {
    MessagingManager::fake();
    $this->tenant = createTenant(slug: 'lumiere');
});

it('lets the App Review phone sign in with the fixed code without sending a message', function () {
    $this->tenant->update(['settings' => array_merge($this->tenant->settings ?? [], ['api' => ['review_phone' => '+974 5555 0000', 'review_code' => '424242']])]);
    $h = apiHeaders($this->tenant->fresh());

    $this->postJson(apiUrl('auth/otp'), ['phone' => '55550000'], $h)->assertAccepted()->assertJsonPath('phone', '+97455550000');
    expect(FakeChannel::sentTo('whatsapp'))->toBeEmpty()->and(FakeChannel::sentTo('sms'))->toBeEmpty();

    $this->postJson(apiUrl('auth/verify'), ['phone' => '+97455550000', 'code' => '424242', 'device_name' => 'Review iPad', 'name' => 'App Review'], $h)
        ->assertOk()->assertJsonPath('client.name', 'App Review');

    // Works again later without requesting a code first; other phones never get the bypass.
    $this->postJson(apiUrl('auth/verify'), ['phone' => '55550000', 'code' => '424242', 'device_name' => 'Review iPad'], $h)->assertOk();
    $this->postJson(apiUrl('auth/verify'), ['phone' => '55551111', 'code' => '424242', 'device_name' => 'x'], $h)->assertStatus(422);
    $this->postJson(apiUrl('auth/otp'), ['phone' => '55551111'], $h)->assertAccepted();
    expect(FakeChannel::sentTo('whatsapp'))->not->toBeEmpty();
});

it('does not bypass OTP when the review settings are absent', function () {
    $this->postJson(apiUrl('auth/verify'), ['phone' => '55550000', 'code' => '424242', 'device_name' => 'x'], apiHeaders($this->tenant))
        ->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
});

it('localises messages from Accept-Language and stamps Content-Language', function () {
    $q = http_build_query(['branch_id' => 999, 'services' => [1], 'date' => '2026-12-01']);
    $this->getJson(apiUrl('availability?'.$q), apiHeaders($this->tenant, extra: ['Accept-Language' => 'ar-QA,ar;q=0.9']))
        ->assertStatus(422)->assertHeader('Content-Language', 'ar')
        ->assertJsonPath('error.code', 'domain_error')
        ->assertJsonPath('message', __('booking.errors.branch_unavailable', [], 'ar'));
    $this->getJson(apiUrl('me'), apiHeaders($this->tenant, extra: ['Accept-Language' => 'ar']))->assertUnauthorized()->assertHeader('Content-Language', 'ar');

    $this->getJson(apiUrl('salon'), apiHeaders($this->tenant, extra: ['Accept-Language' => 'en']))->assertOk()->assertHeader('Content-Language', 'en');
});

it('uses one error envelope for unknown salons, auth failures and missing records', function () {
    $this->getJson(apiUrl('salon'))->assertNotFound()->assertJsonPath('error', ['code' => 'not_found', 'status' => 404]);
    $this->getJson(apiUrl('me'), apiHeaders($this->tenant))->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated')->assertJsonPath('message', __('api.errors.unauthenticated'));
    $client = asTenant($this->tenant, fn () => Client::create(['name' => 'A', 'phone' => '+97455550001']));
    $this->getJson(apiUrl('bookings/NOPE42'), apiHeaders($this->tenant, clientToken($client)))->assertNotFound()->assertJsonPath('error.status', 404)->assertJsonPath('message', __('api.errors.not_found'));
});

it('throttles with the api limiter and supports ETag on authenticated GETs', function () {
    $client = asTenant($this->tenant, fn () => Client::create(['name' => 'A', 'phone' => '+97455550001']));
    $h = apiHeaders($this->tenant, clientToken($client));

    $first = $this->getJson(apiUrl('me'), $h)->assertOk()->assertHeader('X-RateLimit-Limit', 120);
    $this->getJson(apiUrl('me'), $h + ['If-None-Match' => $first->headers->get('ETag')])->assertStatus(304);
    $this->getJson(apiUrl('bookings'), $h)->assertOk()->assertHeader('ETag')->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total'], 'links']);
});
