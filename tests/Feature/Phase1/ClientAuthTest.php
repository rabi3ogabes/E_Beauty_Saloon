<?php

use App\Domain\CRM\Models\Client;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;

beforeEach(fn () => MessagingManager::fake());

it('signs a client in with a WhatsApp OTP and creates the profile on first login', function () {
    $tenant = createTenant(slug: 'lumiere');

    $this->post(tenantUrl($tenant, 'login/code'), ['phone' => '5555 1234'])->assertRedirect();
    expect(FakeChannel::sentTo('whatsapp', '+97455551234'))->toHaveCount(1);

    $this->post(tenantUrl($tenant, 'login/verify'), ['phone' => '+97455551234', 'code' => '123456', 'name' => 'Aisha'])
        ->assertRedirect('/account');

    $client = asTenant($tenant, fn () => Client::where('phone', '+97455551234')->first());
    expect($client->name)->toBe('Aisha');
    $this->assertAuthenticatedAs($client, 'client');
    $this->get(tenantUrl($tenant, 'account'))->assertOk()->assertSee('Aisha');
});

it('falls back to SMS when WhatsApp fails and rejects wrong codes', function () {
    $tenant = createTenant();
    FakeChannel::$failFor['whatsapp'] = true;

    $this->post(tenantUrl($tenant, 'login/code'), ['phone' => '+97455550000']);
    expect(FakeChannel::sentTo('sms', '+97455550000'))->toHaveCount(1);

    $this->post(tenantUrl($tenant, 'login/verify'), ['phone' => '+97455550000', 'code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest('client');
});

it('keeps client sessions inside their own salon', function () {
    $a = createTenant(slug: 'alpha');
    $b = createTenant(slug: 'beta');
    $client = asTenant($a, fn () => Client::create(['name' => 'A', 'phone' => '+97455551111']));

    $this->actingAs($client, 'client')->get(tenantUrl($a, 'account'))->assertOk();
    $this->get(tenantUrl($b, 'account'))->assertRedirect();
});
