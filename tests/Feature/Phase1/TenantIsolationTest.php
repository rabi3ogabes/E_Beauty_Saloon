<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\CRM\Models\Client;

it('never leaks tenant B rows into tenant A queries', function () {
    $a = createTenant(slug: 'alpha');
    $b = createTenant(slug: 'beta');

    asTenant($a, fn () => Client::create(['name' => 'Alpha Client', 'phone' => '55000001']));
    asTenant($b, fn () => Client::create(['name' => 'Beta Client', 'phone' => '55000001'])); // same phone allowed across tenants

    asTenant($a, function () {
        expect(Client::pluck('name')->all())->toBe(['Alpha Client'])
            ->and(Branch::count())->toBe(1)
            ->and(Client::where('name', 'Beta Client')->exists())->toBeFalse();
    });
});

it('stamps tenant_id automatically and refuses to create tenant rows without a tenant', function () {
    $a = createTenant();
    $client = asTenant($a, fn () => Client::create(['name' => 'X', 'phone' => '+97455112233']));
    expect($client->tenant_id)->toBe($a->id);

    Client::create(['name' => 'Orphan', 'phone' => '1']);
})->throws(RuntimeException::class);

it('does not let a tenant user sign into another tenant dashboard', function () {
    $a = createTenant(slug: 'alpha');
    $b = createTenant(slug: 'beta');

    $this->actingAs($a->owner)->get(tenantUrl($b, 'dashboard'))->assertForbidden();
    $this->actingAs($a->owner)->get(tenantUrl($a, 'dashboard'))->assertOk();
});

it('keeps platform admins out of tenant dashboards and owners out of /admin', function () {
    $a = createTenant(slug: 'alpha');

    $this->actingAs(platformAdmin())->get(tenantUrl($a, 'dashboard'))->assertForbidden();
    $this->actingAs($a->owner)->get(centralUrl('admin'))->assertForbidden();
});
