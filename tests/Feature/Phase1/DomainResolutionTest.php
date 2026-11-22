<?php

use App\Domain\Tenancy\Actions\AddCustomDomain;
use App\Domain\Tenancy\Domains\DnsResolver;
use App\Domain\Tenancy\Jobs\VerifyCustomDomain;

it('serves the salon website on its subdomain and the platform site on the central domain', function () {
    $tenant = createTenant(slug: 'lumiere');

    $this->get(tenantUrl($tenant))->assertOk()->assertSee('Lumiere');
    $this->get(centralUrl())->assertOk()->assertSee(config('salon.name'));
});

it('returns 404 for unknown hosts on tenant-only routes and hides /admin on tenant domains', function () {
    $tenant = createTenant(slug: 'lumiere');

    $this->get('http://unknown.salon.test/locked')->assertNotFound();
    $this->get(tenantUrl($tenant, 'admin/login'))->assertNotFound();
    $this->get(centralUrl('dashboard/login'))->assertNotFound();
    $this->get(tenantUrl($tenant, 'dashboard/login'))->assertOk();
});

it('verifies a custom domain through DNS and then serves the tenant there', function () {
    $tenant = createTenant(slug: 'lumiere');
    $domain = app(AddCustomDomain::class)->handle($tenant, 'https://Book.LumiereDoha.com/');

    expect($domain->domain)->toBe('book.lumieredoha.com')->and($domain->isVerified())->toBeFalse();

    // Not yet pointed -> stays unverified, tenant not served on it
    $dns = Mockery::mock(DnsResolver::class);
    $dns->shouldReceive('txt')->andReturn([]);
    $dns->shouldReceive('cname')->andReturn([]);
    $dns->shouldReceive('a')->andReturn([]);
    app()->instance(DnsResolver::class, $dns);
    VerifyCustomDomain::dispatchSync($domain->id);
    expect($domain->fresh()->isVerified())->toBeFalse();
    $this->get('http://book.lumieredoha.com/locked')->assertNotFound();

    // Records in place -> verified + SSL active
    $dns = Mockery::mock(DnsResolver::class);
    $dns->shouldReceive('txt')->with('_luxe.book.lumieredoha.com')->andReturn([$domain->dns_token]);
    $dns->shouldReceive('cname')->andReturn([config('salon.domains.cname_target')]);
    $dns->shouldReceive('a')->andReturn([]);
    app()->instance(DnsResolver::class, $dns);
    VerifyCustomDomain::dispatchSync($domain->id);

    expect($domain->fresh()->isVerified())->toBeTrue()->and($domain->fresh()->ssl_status)->toBe('active');
    cache()->flush();
    $this->get('http://book.lumieredoha.com/')->assertOk()->assertSee('Lumiere');
    $this->get(centralUrl('internal/caddy/ask?domain=book.lumieredoha.com'))->assertOk();
    $this->get(centralUrl('internal/caddy/ask?domain=evil.example.com'))->assertNotFound();
});

it('rejects invalid, reserved and duplicate domains', function () {
    $tenant = createTenant();
    expect(fn () => app(AddCustomDomain::class)->handle($tenant, 'not a domain'))->toThrow(\App\Support\Exceptions\DomainException::class)
        ->and(fn () => app(AddCustomDomain::class)->handle($tenant, 'other.salon.test'))->toThrow(\App\Support\Exceptions\DomainException::class);
    app(AddCustomDomain::class)->handle($tenant, 'mine.com');
    expect(fn () => app(AddCustomDomain::class)->handle(createTenant(), 'mine.com'))->toThrow(\App\Support\Exceptions\DomainException::class);
});
