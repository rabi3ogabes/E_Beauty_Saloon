<?php

require_once __DIR__.'/WorkspaceHelpers.php';

use App\Domain\Billing\Actions\MarkInvoicePaid;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Branches\Actions\CreateBranch;
use App\Domain\Branches\Actions\NormalizeOpeningHours;
use App\Domain\Branches\Actions\PurchaseBranchSlot;
use App\Domain\Branches\Models\Branch;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Filament\Dashboard\Resources\Branches\BranchResource;
use App\Filament\Dashboard\Resources\Branches\Pages\CreateBranch as CreateBranchPage;
use App\Filament\Dashboard\Resources\Branches\Pages\EditBranch;
use App\Filament\Dashboard\Resources\Branches\Pages\ListBranches;
use App\Filament\Dashboard\Resources\Branches\RelationManagers\HolidaysRelationManager;
use App\Filament\Dashboard\Resources\Branches\RelationManagers\ResourcesRelationManager;
use App\Support\Exceptions\DomainException;
use Carbon\Carbon;
use Livewire\Livewire;

it('creates branches up to the plan limit and refuses beyond it', function () {
    $tenant = createTenant(branches: 2);
    ws_owner_session($this, $tenant);

    $branch = app(CreateBranch::class)->handle(['name' => ['en' => 'The Pearl', 'ar' => 'اللؤلؤة']]);
    expect($branch->slug)->toBe('the-pearl')
        ->and($branch->opening_hours)->toHaveKey('mon')
        ->and(CreateBranch::atLimit())->toBeTrue();

    app(CreateBranch::class)->handle(['name' => ['en' => 'Lusail']]);
})->throws(DomainException::class);

it('shows the prorated upgrade price at the limit and issues the proration invoice', function () {
    $tenant = createTenant('essential', 1);
    ws_owner_session($this, $tenant);

    Livewire::test(ListBranches::class)
        ->assertActionHidden('create')
        ->assertActionVisible('addBranchUpgrade')
        ->callAction('addBranchUpgrade')
        ->assertHasNoActionErrors();

    expect(ListBranches::upgradeQuote()->toHtml())->toContain(money(14900));

    $invoice = $tenant->invoices()->latest('id')->first();
    expect($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->items->first()->type)->toBe('proration')
        ->and($invoice->items->first()->meta['change']['branches_count'])->toBe(2)
        ->and($invoice->total)->toBeGreaterThan(0);
});

it('purchase branch slot action raises the limit once the invoice is paid', function () {
    $tenant = createTenant('essential', 1);
    ws_owner_session($this, $tenant);

    $invoice = app(PurchaseBranchSlot::class)->handle($tenant);
    app(MarkInvoicePaid::class)->handle($invoice, 'manual', 'T-1');

    expect($tenant->fresh()->branchLimit())->toBe(2);
});

it('normalizes the weekly hours editor with breaks and closed days', function () {
    $rows = NormalizeOpeningHours::toRows(null);
    $rows[5]['closed'] = true; // Friday
    $rows[1]['breaks'] = [['from' => '14:00', 'to' => '15:00'], ['from' => '16:00', 'to' => '15:00']];

    $hours = app(NormalizeOpeningHours::class)->handle($rows);

    expect($hours['fri']['closed'])->toBeTrue()
        ->and($hours['mon']['breaks'])->toBe([['14:00', '15:00']]);
});

it('creates and edits a branch with hours, a partial-day holiday and resources from the dashboard', function () {
    $tenant = createTenant(branches: 3);
    ws_owner_session($this, $tenant);

    $rows = NormalizeOpeningHours::toRows(null);
    $rows[5]['closed'] = true;

    Livewire::test(CreateBranchPage::class)
        ->fillForm(['name' => ['en' => 'West Bay', 'ar' => 'الخليج الغربي'], 'status' => 'active', 'timezone' => 'Asia/Qatar', 'hours' => $rows, 'lat' => 25.32, 'lng' => 51.53])
        ->call('create')
        ->assertHasNoFormErrors();

    $branch = Branch::where('slug', 'west-bay')->firstOrFail();
    expect($branch->opening_hours['fri']['closed'])->toBeTrue()->and($branch->lat)->toBe(25.32);

    Livewire::test(EditBranch::class, ['record' => $branch->id])
        ->fillForm(['phone' => '+97444440000'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($branch->fresh()->phone)->toBe('+97444440000');

    Livewire::test(HolidaysRelationManager::class, ['ownerRecord' => $branch, 'pageClass' => EditBranch::class])
        ->callTableAction('create', data: ['date' => '2026-12-18', 'name' => 'National Day', 'partial' => true, 'opens_at' => '14:00', 'closes_at' => '20:00'])
        ->assertHasNoTableActionErrors();

    expect($branch->hoursFor(Carbon::parse('2026-12-18')))->toMatchArray(['open' => '14:00', 'close' => '20:00']);

    Livewire::test(ResourcesRelationManager::class, ['ownerRecord' => $branch, 'pageClass' => EditBranch::class])
        ->callTableAction('create', data: ['name' => 'Chair 1', 'type' => 'chair', 'capacity' => 1, 'is_active' => true])
        ->assertHasNoTableActionErrors();
    expect($branch->resources()->count())->toBe(1);
});

it('hides create/edit/delete during the grace period', function () {
    $tenant = createTenant(branches: 3);
    ws_owner_session($this, $tenant);
    expect(BranchResource::canCreate())->toBeTrue();

    $tenant->update(['status' => TenantStatus::Grace]);
    asTenant($tenant->fresh());

    expect(BranchResource::canCreate())->toBeFalse()
        ->and(BranchResource::canEdit(ws_main_branch()))->toBeFalse()
        ->and(BranchResource::canViewAny())->toBeTrue();
});
