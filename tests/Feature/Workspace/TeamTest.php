<?php

require_once __DIR__.'/WorkspaceHelpers.php';

use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Inventory\Models\BranchStock;
use App\Domain\Team\Actions\InviteTeamMember;
use App\Domain\Team\Actions\ReviewTimeOff;
use App\Domain\Team\Actions\SetRolePermission;
use App\Domain\Team\Actions\SyncStaffSkills;
use App\Domain\Team\Actions\UpdateTeamMember;
use App\Domain\Team\Models\Staff;
use App\Domain\Team\Models\TimeOff;
use App\Filament\Dashboard\Pages\RolesPermissions;
use App\Filament\Dashboard\Resources\Clients\ClientResource;
use App\Filament\Dashboard\Resources\Staff\Pages\CreateStaff;
use App\Filament\Dashboard\Resources\Staff\Pages\EditStaff;
use App\Filament\Dashboard\Resources\Staff\RelationManagers\CommissionRulesRelationManager;
use App\Filament\Dashboard\Resources\Staff\RelationManagers\SchedulesRelationManager;
use App\Filament\Dashboard\Resources\TeamMembers\Pages\ManageTeamMembers;
use App\Filament\Dashboard\Resources\TimeOff\Pages\ManageTimeOff;
use App\Models\User;
use App\Support\Exceptions\DomainException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('invites a team member with a role and branches and emails a set-password link', function () {
    Notification::fake();
    $tenant = createTenant(branches: 2);
    ws_owner_session($this, $tenant);
    $staff = Staff::create(['name' => ['en' => 'Lina']]);

    Livewire::test(ManageTeamMembers::class)
        ->callAction('invite', data: ['name' => 'Lina Haddad', 'email' => 'lina@example.com', 'role' => 'stylist', 'branch_ids' => [ws_main_branch()->id], 'staff_id' => $staff->id])
        ->assertHasNoActionErrors();

    $user = User::where('email', 'lina@example.com')->firstOrFail();
    expect($user->tenant_id)->toBe($tenant->id)
        ->and($user->hasRole('stylist'))->toBeTrue()
        ->and($user->restrictedBranchIds())->toBe([ws_main_branch()->id])
        ->and($staff->fresh()->user_id)->toBe($user->id);
    Notification::assertSentTo($user, ResetPassword::class);
});

it('refuses unknown roles and duplicate emails', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);
    app(InviteTeamMember::class)->handle('X', $tenant->owner->email, 'stylist', sendInvite: false);
})->throws(DomainException::class);

it('changes a member role and branches but never the owner', function () {
    $tenant = createTenant(branches: 2);
    ws_owner_session($this, $tenant);
    $second = ws_branch('lusail');
    $user = app(InviteTeamMember::class)->handle('Mona', 'mona@example.com', 'receptionist', [ws_main_branch()->id], sendInvite: false);

    app(UpdateTeamMember::class)->handle($user, 'branch_manager', [$second->id], true);
    expect($user->fresh()->hasRole('branch_manager'))->toBeTrue()
        ->and($user->fresh()->hasRole('receptionist'))->toBeFalse()
        ->and($user->fresh()->restrictedBranchIds())->toBe([$second->id]);

    expect(fn () => app(UpdateTeamMember::class)->handle($tenant->owner, 'stylist', []))->toThrow(DomainException::class);
});

it('approves and rejects time-off requests once', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);
    $staff = Staff::create(['name' => ['en' => 'Lina']]);
    $staff->branches()->attach(ws_main_branch());
    $a = TimeOff::create(['staff_id' => $staff->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'status' => 'pending']);
    $b = TimeOff::create(['staff_id' => $staff->id, 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addDay(), 'status' => 'pending']);

    Livewire::test(ManageTimeOff::class)->callTableAction('approve', $a)->assertHasNoTableActionErrors();
    app(ReviewTimeOff::class)->handle($b, false);

    expect($a->fresh()->status)->toBe('approved')->and($a->fresh()->approved_by)->toBe($tenant->owner->id)
        ->and($b->fresh()->status)->toBe('rejected');
    expect(fn () => app(ReviewTimeOff::class)->handle($b->fresh(), true))->toThrow(DomainException::class);
});

it('toggles permissions in the roles matrix and the change takes effect', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);
    $stylist = tenantUser($tenant, 'stylist', [ws_main_branch()->id]);
    asTenant($tenant);
    expect($stylist->fresh()->can('inventory.view'))->toBeFalse();

    Livewire::test(RolesPermissions::class)->call('toggle', 'stylist', 'inventory.view')->assertHasNoErrors();

    $role = Role::where('name', 'stylist')->where('tenant_id', $tenant->id)->first();
    expect($role->hasPermissionTo('inventory.view'))->toBeTrue()
        ->and($stylist->fresh()->can('inventory.view'))->toBeTrue();

    Livewire::test(RolesPermissions::class)->call('toggle', 'stylist', 'inventory.view');
    expect($role->fresh()->hasPermissionTo('inventory.view'))->toBeFalse();

    expect(fn () => app(SetRolePermission::class)->handle('owner', 'inventory.view', false))->toThrow(DomainException::class)
        ->and(fn () => app(SetRolePermission::class)->handle('stylist', 'platform.tenants.view', true))->toThrow(DomainException::class);
});

it('keeps the roles matrix for owners only (stylists are denied)', function () {
    $tenant = createTenant();
    $stylist = tenantUser($tenant, 'stylist', [asTenant($tenant, fn () => ws_main_branch()->id)]);
    $this->actingAs($stylist)->get(tenantUrl($tenant, 'dashboard/roles'))->assertForbidden();
});

it('syncs staff skills with own price and duration, schedules with breaks and commission rules', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);
    $service = Service::create(['name' => ['en' => 'Balayage'], 'slug' => 'balayage', 'duration_minutes' => 180, 'price' => 95000]);

    Livewire::test(CreateStaff::class)
        ->fillForm(['name' => ['en' => 'Lina', 'ar' => 'لينا'], 'color' => '#7A1C34', 'branches' => [ws_main_branch()->id], 'is_bookable' => true, 'is_active' => true,
            'skills' => [['service_id' => $service->id, 'price' => '1100.00', 'duration_minutes' => 200]]])
        ->call('create')->assertHasNoFormErrors();

    $staff = Staff::firstOrFail();
    $staff->load('services');
    expect($service->priceAt(ws_main_branch(), $staff))->toBe(110000)
        ->and($service->durationAt(ws_main_branch(), $staff))->toBe(200);

    Livewire::test(SchedulesRelationManager::class, ['ownerRecord' => $staff, 'pageClass' => EditStaff::class])
        ->callTableAction('create', data: ['branch_id' => ws_main_branch()->id, 'weekdays' => [0, 1], 'starts_at' => '10:00', 'ends_at' => '19:00', 'breaks' => [['from' => '14:00', 'to' => '15:00']]])
        ->assertHasNoTableActionErrors();
    expect($staff->schedules()->count())->toBe(2)->and($staff->schedules()->first()->breaks)->toBe([['14:00', '15:00']]);

    Livewire::test(CommissionRulesRelationManager::class, ['ownerRecord' => $staff, 'pageClass' => EditStaff::class])
        ->callTableAction('create', data: ['applies_to' => 'service', 'target_id' => $service->id, 'type' => 'percent', 'value_input' => 15, 'tiers_input' => [['from' => '20000', 'value' => 20]]])
        ->assertHasNoTableActionErrors();
    $rule = $staff->commissionRules()->first();
    expect($rule->value)->toBe(1500)->and($rule->tiers)->toBe([['from' => 2000000, 'value' => 2000]])
        ->and($rule->target_type)->toBe((new Service)->getMorphClass());

    app(SyncStaffSkills::class)->handle($staff, []);
    expect($staff->services()->count())->toBe(0);
});

it('stylist only sees own branch(es) and a manager cannot see other branches revenue or data', function () {
    $tenant = createTenant(branches: 3);
    [$main, $pearl] = asTenant($tenant, function () {
        $pearl = ws_branch('pearl');
        $v = ws_variant('SKU-A');
        app(StockLedger::class)->move(ws_main_branch()->id, $v->id, 5, 'purchase');
        app(StockLedger::class)->move($pearl->id, $v->id, 9, 'purchase');
        foreach ([ws_main_branch(), $pearl] as $i => $b) {
            Booking::create(['branch_id' => $b->id, 'starts_at' => now(), 'ends_at' => now()->addHour(), 'total' => 10000 * ($i + 1), 'status' => 'completed']);
            $s = Staff::create(['name' => ['en' => 'Staff '.$b->slug]]);
            $s->branches()->attach($b);
        }

        return [ws_main_branch(), $pearl];
    });

    $stylist = tenantUser($tenant, 'stylist', [$main->id]);
    $manager = tenantUser($tenant, 'branch_manager', [$pearl->id]);

    // Stylist at Main: the branch list and staff list only show Main.
    $this->actingAs($stylist)->get(tenantUrl($tenant, 'dashboard'))->assertOk();
    $ctx = app(TenantContext::class);
    asTenant($tenant);
    $ctx->restrictToBranches($stylist->restrictedBranchIds());
    expect(Branch::pluck('slug')->all())->toBe(['main'])
        ->and(Staff::get()->map->name->all())->toBe(['Staff main'])
        ->and(Booking::sum('total'))->toBe(10000);

    // Manager at Pearl: only Pearl revenue / stock; no cross-branch lifetime value.
    asTenant($tenant);
    $ctx->restrictToBranches($manager->restrictedBranchIds());
    $this->actingAs($manager);
    expect(Booking::sum('total'))->toBe(20000)
        ->and(BranchStock::sum('qty'))->toBe(9)
        ->and(ClientResource::canSeeRevenue())->toBeFalse();

    // Over HTTP: the manager's branches page lists Pearl and not Main.
    $this->flushSession();
    $this->actingAs($manager)->get(tenantUrl($tenant, 'dashboard/branches'))->assertOk()->assertSee('Pearl')->assertDontSee('الرئيسي');
    $this->actingAs($manager)->get(tenantUrl($tenant, 'dashboard/stock'))->assertOk();
});
