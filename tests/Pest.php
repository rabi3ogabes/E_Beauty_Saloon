<?php

use App\Domain\Billing\Actions\MarkInvoicePaid;
use App\Domain\Billing\Models\Plan;
use App\Domain\Branches\Models\Branch;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Test bootstrap
|--------------------------------------------------------------------------
| Every Feature/Domain test runs on a fresh in-memory database with the
| platform catalog (modules, plans, roles) seeded.
*/

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed(PlatformSeeder::class);
        app(TenantContext::class)->set(null);
    })
    ->in('Feature', 'Domain');

pest()->extend(Tests\TestCase::class)->in('Unit');

/**
 * Create a paid, active salon. $modules are add-ons on top of the plan (default plan: maison = all modules).
 */
function createTenant(string $plan = 'maison', int $branches = 1, array $modules = [], ?string $slug = null, bool $withBranch = true): Tenant
{
    $slug ??= 'salon-'.Str::lower(Str::random(6));
    $tenant = app(CreateTenant::class)->handle([
        'name' => Str::headline($slug),
        'slug' => $slug,
        'owner_name' => 'Owner '.$slug,
        'owner_email' => $slug.'@example.com',
        'owner_password' => 'password-1234',
    ], ['plan_id' => Plan::where('slug', $plan)->value('id'), 'cycle_months' => 1, 'branches' => $branches, 'modules' => $modules]);

    app(MarkInvoicePaid::class)->handle($tenant->invoices()->firstOrFail(), 'manual', 'TEST');
    $tenant = $tenant->fresh();

    if ($withBranch) {
        app(TenantContext::class)->run($tenant, fn () => Branch::create([
            'name' => ['en' => 'Main', 'ar' => 'الرئيسي'], 'slug' => 'main', 'opening_hours' => Branch::defaultHours(),
        ]));
    }

    return $tenant;
}

/** Run a closure inside a tenant context. */
function asTenant(Tenant $tenant, ?Closure $callback = null): mixed
{
    if ($callback) {
        return app(TenantContext::class)->run($tenant, $callback);
    }
    app(TenantContext::class)->set($tenant);

    return null;
}

/** Base URL for a tenant's subdomain in tests. */
function tenantUrl(Tenant $tenant, string $path = '/'): string
{
    return 'http://'.$tenant->slug.'.'.config('salon.base_domain').'/'.ltrim($path, '/');
}

function centralUrl(string $path = '/'): string
{
    return 'http://'.config('salon.central_domains')[0].'/'.ltrim($path, '/');
}

function platformAdmin(): User
{
    return User::where('email', 'admin@luxesalon.test')->firstOrFail();
}

/** Create a tenant staff user with a role, optionally restricted to branches. */
function tenantUser(Tenant $tenant, string $role = 'owner', array $branchIds = []): User
{
    return asTenant($tenant, function () use ($tenant, $role, $branchIds) {
        if ($role === 'owner') {
            return $tenant->owner;
        }
        $u = new User(['name' => ucfirst($role), 'email' => $role.'.'.Str::random(5).'@example.com', 'password' => 'password-1234']);
        $u->tenant_id = $tenant->id;
        $u->save();
        $u->assignRole($role);
        $u->branches()->sync($branchIds);

        return $u;
    });
}
