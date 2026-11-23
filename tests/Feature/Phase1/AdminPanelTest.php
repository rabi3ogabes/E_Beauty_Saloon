<?php

use App\Domain\Billing\Models\Plan;
use App\Domain\Tenancy\Models\ImpersonationLog;
use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->actingAs(platformAdmin());
    Filament::setCurrentPanel('admin');
});

it('renders every admin page for the platform owner', function () {
    $tenant = createTenant();
    foreach (['admin', 'admin/tenants', 'admin/tenants/create', "admin/tenants/{$tenant->id}", 'admin/invoices', 'admin/plans', 'admin/coupons',
        'admin/modules', 'admin/platform-payments', 'admin/impersonation-logs', 'admin/leads', 'admin/activity-logs'] as $path) {
        $this->get(centralUrl($path))->assertOk();
    }
});

it('creates a salon from the admin wizard with a live quote', function () {
    $plan = Plan::where('slug', 'essential')->first();

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Velvet Room', 'slug' => 'velvet', 'locale' => 'en', 'timezone' => 'Asia/Qatar',
            'owner_name' => 'Dana', 'owner_email' => 'dana@example.com', 'send_invite' => false, 'owner_password' => 'secret-password',
            'plan_id' => $plan->id, 'cycle_months' => 6, 'branches' => 3, 'modules' => ['pos'], 'trial' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tenant = Tenant::where('slug', 'velvet')->firstOrFail();
    expect($tenant->invoices()->first()->total)->toBe((int) ((29900 * 6 + 2 * 14900 * 6 + 9900 * 3 * 6) * 0.9));
});

it('starts an impersonation from the tenant page and logs the reason', function () {
    $tenant = createTenant();

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->callAction('impersonate', data: ['reason' => 'Helping with booking setup'])
        ->assertHasNoActionErrors();

    expect(ImpersonationLog::where('tenant_id', $tenant->id)->value('reason'))->toBe('Helping with booking setup');
});

it('denies support staff access to billing resources', function () {
    $support = User::forceCreate(['name' => 'Sam', 'email' => 'sam@luxe.test', 'password' => bcrypt('x'), 'is_platform' => true]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $support->assignRole('support');

    $this->actingAs($support)->get(centralUrl('admin/tenants'))->assertOk();
    $this->actingAs($support)->get(centralUrl('admin/invoices'))->assertForbidden();
});
