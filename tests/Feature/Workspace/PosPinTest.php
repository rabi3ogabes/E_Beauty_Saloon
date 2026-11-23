<?php

require_once __DIR__.'/WorkspaceHelpers.php';

use App\Domain\POS\Actions\VerifyPosPin;
use App\Domain\Team\Actions\SetPosPin;
use App\Filament\Auth\EditProfile;
use App\Filament\Dashboard\Resources\TeamMembers\Pages\ManageTeamMembers;
use App\Support\Exceptions\DomainException;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('lets the owner set a team member PIN that then approves at the till', function () {
    $tenant = createTenant(modules: ['pos']);
    $manager = tenantUser($tenant, 'branch_manager');
    ws_owner_session($this, $tenant);

    Livewire::test(ManageTeamMembers::class)
        ->callTableAction('setPosPin', $manager, data: ['pin' => '4826'])
        ->assertHasNoTableActionErrors();

    expect($manager->fresh()->checkPosPin('4826'))->toBeTrue()
        ->and(app(VerifyPosPin::class)->handle('4826', 'pos.discount')->is($manager))->toBeTrue();
});

it('removes a PIN', function () {
    $tenant = createTenant(modules: ['pos']);
    $cashier = tenantUser($tenant, 'receptionist');
    ws_owner_session($this, $tenant);
    $cashier->setPosPin('4826');

    Livewire::test(ManageTeamMembers::class)
        ->callTableAction('setPosPin', $cashier, data: ['remove' => true])
        ->assertHasNoTableActionErrors();

    expect($cashier->fresh()->pos_pin)->toBeNull();
});

it('rejects weak, malformed and already-used PINs with one generic message', function (string $pin) {
    $tenant = createTenant(modules: ['pos']);
    $other = tenantUser($tenant, 'receptionist');
    $me = tenantUser($tenant, 'branch_manager');
    ws_owner_session($this, $tenant);
    $other->setPosPin('4826');

    app(SetPosPin::class)->handle($me, $pin);
})->with(['repeated' => '7777', 'sequence' => '3456', 'descending' => '9876', 'taken' => '4826', 'letters' => '12ab', 'short' => '482'])
    ->throws(DomainException::class);

it('throttles repeated rejected PIN attempts', function () {
    $tenant = createTenant(modules: ['pos']);
    $me = tenantUser($tenant, 'branch_manager');
    ws_owner_session($this, $tenant);

    foreach (range(1, 5) as $_) {
        try {
            app(SetPosPin::class)->handle($me, '1111');
        } catch (DomainException) {
        }
    }

    expect(fn () => app(SetPosPin::class)->handle($me, '4826'))
        ->toThrow(DomainException::class, 'Too many attempts');
});

it('lets a team member set their own PIN from their profile after confirming their password', function () {
    $tenant = createTenant(modules: ['pos']);
    $cashier = tenantUser($tenant, 'receptionist');
    Filament::setCurrentPanel('pos');
    asTenant($tenant);
    $this->actingAs($cashier);

    Livewire::test(EditProfile::class)
        ->fillForm(['posPin' => '5193', 'posPinConfirmation' => '5193', 'currentPassword' => 'wrong-password'])
        ->call('save')
        ->assertHasFormErrors(['currentPassword']);
    expect($cashier->fresh()->pos_pin)->toBeNull();

    Livewire::test(EditProfile::class)
        ->fillForm(['posPin' => '5193', 'posPinConfirmation' => '5193', 'currentPassword' => 'password-1234'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($cashier->fresh()->checkPosPin('5193'))->toBeTrue();
});

it('shows a clear error on the profile when the PIN cannot be used', function () {
    $tenant = createTenant(modules: ['pos']);
    $cashier = tenantUser($tenant, 'receptionist');
    Filament::setCurrentPanel('dashboard');
    asTenant($tenant);
    $this->actingAs($cashier);

    Livewire::test(EditProfile::class)
        ->fillForm(['posPin' => '1234', 'posPinConfirmation' => '1234', 'currentPassword' => 'password-1234'])
        ->call('save')
        ->assertHasErrors(['data.posPin']);
    expect($cashier->fresh()->pos_pin)->toBeNull();
});

it('hides the PIN section from roles without POS access', function () {
    $tenant = createTenant(modules: ['pos']);
    $stylist = tenantUser($tenant, 'stylist');
    Filament::setCurrentPanel('dashboard');
    asTenant($tenant);
    $this->actingAs($stylist);

    expect(EditProfile::usesPosPin())->toBeFalse();
});

it('prefers the PIN holder who can approve when older data shares a PIN', function () {
    $tenant = createTenant(modules: ['pos']);
    $cashier = tenantUser($tenant, 'receptionist');
    $manager = tenantUser($tenant, 'branch_manager');
    ws_owner_session($this, $tenant);
    $cashier->setPosPin('1234');
    $manager->setPosPin('1234');

    expect(app(VerifyPosPin::class)->handle('1234', 'pos.refund')->is($manager))->toBeTrue();
});
