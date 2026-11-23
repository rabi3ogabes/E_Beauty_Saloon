<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'tenant:required', 'module:shop'])->get('/__test/shop', fn () => 'shop ok');
    Route::middleware(['api', 'tenant:required', 'module:shop'])->get('/api/__test/shop', fn () => ['ok' => true]);
});

it('blocks routes and API of modules that are not in the subscription', function () {
    $tenant = createTenant('essential'); // website + booking only

    $this->get(tenantUrl($tenant, '__test/shop'))->assertForbidden();
    $this->getJson(tenantUrl($tenant, 'api/__test/shop'))->assertForbidden()->assertJsonPath('module', 'shop');
    $this->getJson(centralUrl('api/__test/shop'), ['X-Tenant' => $tenant->slug])->assertForbidden();
});

it('allows the module after it is added to the subscription', function () {
    $tenant = createTenant('essential', 1, ['shop']);

    $this->get(tenantUrl($tenant, '__test/shop'))->assertOk()->assertSee('shop ok');
    $this->getJson(centralUrl('api/__test/shop'), ['X-Tenant' => $tenant->slug])->assertOk();
});

it('blocks the POS panel when POS is not subscribed', function () {
    $tenant = createTenant('essential');
    $this->get(tenantUrl($tenant, 'pos/login'))->assertForbidden();

    $withPos = createTenant('essential', 1, ['pos']);
    $this->get(tenantUrl($withPos, 'pos/login'))->assertOk();
});
