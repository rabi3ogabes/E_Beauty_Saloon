<?php

use App\Domain\Tenancy\Actions\StartImpersonation;
use App\Domain\Tenancy\Models\ImpersonationLog;
use App\Support\Exceptions\DomainException;

it('lets platform staff open a tenant dashboard as the owner, logs it, and exits back to /admin', function () {
    $tenant = createTenant(slug: 'lumiere');
    $admin = platformAdmin();

    $url = app(StartImpersonation::class)->handle($admin, $tenant, 'Owner asked for help with SADAD');
    expect($url)->toStartWith('http://lumiere.salon.test')->toContain('/impersonate/');

    $this->get($url)->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($tenant->owner);

    $log = ImpersonationLog::first();
    expect($log->reason)->toBe('Owner asked for help with SADAD')
        ->and($log->started_at)->not->toBeNull()
        ->and($log->token)->toBeNull()
        ->and($log->admin_user_id)->toBe($admin->id);

    $this->get(tenantUrl($tenant, 'dashboard'))->assertOk()->assertSee('You are viewing as');

    $this->get(tenantUrl($tenant, 'impersonate/exit'))->assertRedirect(centralUrl('admin/tenants'));
    expect($log->fresh()->ended_at)->not->toBeNull();
    $this->assertGuest();
});

it('rejects reused, expired or foreign impersonation tokens', function () {
    $tenant = createTenant(slug: 'lumiere');
    $other = createTenant(slug: 'other');
    $url = app(StartImpersonation::class)->handle(platformAdmin(), $tenant, 'Testing tokens');
    $token = basename($url);

    $this->get(tenantUrl($other, 'impersonate/'.$token))->assertForbidden();
    $this->get($url)->assertRedirect('/dashboard');
    auth()->logout();
    $this->get($url)->assertForbidden(); // single use

    $url2 = app(StartImpersonation::class)->handle(platformAdmin(), $tenant, 'Testing expiry');
    $this->travel(2)->minutes();
    $this->get($url2)->assertForbidden();
});

it('refuses impersonation by non-platform users', function () {
    $tenant = createTenant();
    app(StartImpersonation::class)->handle($tenant->owner, $tenant, 'nope');
})->throws(DomainException::class);
