<?php

require_once __DIR__.'/PosTestHelpers.php';

use App\Domain\Branches\Models\Branch;
use App\Domain\POS\Actions\CompleteSale;
use App\Domain\POS\Actions\OpenShift;
use App\Domain\POS\Models\Register;
use App\Domain\POS\Models\Sale;
use App\Filament\Dashboard\Pages\CommissionReport;
use App\Filament\Dashboard\Resources\PosSales\Pages\ListSales;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

// Production-like strictness: catch N+1 / lazy loading in POS code paths.
beforeEach(fn () => Model::preventLazyLoading());
afterEach(fn () => Model::preventLazyLoading(false));

it('forbids the POS when the module is disabled', function () {
    $tenant = createTenant('essential'); // no POS
    $owner = $tenant->owner;

    $this->actingAs($owner)->get(tenantUrl($tenant, 'pos'))->assertForbidden();
    $this->actingAs($owner)->postJson(tenantUrl($tenant, 'pos/sync'), ['sales' => []])->assertForbidden();
    $this->actingAs($owner)->get(tenantUrl($tenant, 'dashboard/pos-sales'))->assertForbidden();
    $this->actingAs($owner)->get(tenantUrl($tenant, 'dashboard/reports/commissions'))->assertForbidden();
});

it('only shows a branch manager the sales of their own branch', function () {
    $s = posSalon(['tax_percent' => 0]);
    $other = asTenant($s->tenant, function () use ($s) {
        $branch = Branch::create(['name' => ['en' => 'Pearl'], 'slug' => 'pearl', 'opening_hours' => Branch::defaultHours()]);
        $register = Register::create(['branch_id' => $branch->id, 'name' => 'Pearl desk']);
        $shift = app(OpenShift::class)->handle($register, $s->owner, 0);
        foreach ([[$s->branch->id, $s->shift->id], [$branch->id, $shift->id]] as [$branchId, $shiftId]) {
            app(CompleteSale::class)->handle([
                'idempotency_key' => saleKey(), 'branch_id' => $branchId, 'shift_id' => $shiftId,
                'lines' => [['type' => 'service', 'id' => $s->services[2]->id]], 'payments' => [['method' => 'cash', 'tendered' => 6000]],
            ], $s->owner);
        }

        return $branch;
    });
    $manager = tenantUser($s->tenant, 'branch_manager', [$s->branch->id]);

    posActingAs($s->tenant, $manager);
    Filament::setCurrentPanel('dashboard');
    $own = Sale::withoutGlobalScopes()->where('branch_id', $s->branch->id)->first();
    $foreign = Sale::withoutGlobalScopes()->where('branch_id', $other->id)->first();

    Livewire::test(ListSales::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign]);
    expect(Sale::count())->toBe(1);

    $this->actingAs($manager)->get(tenantUrl($s->tenant, "pos/sales/{$foreign->id}/receipt"))->assertNotFound();
    $this->actingAs($manager)->get(tenantUrl($s->tenant, "pos/sales/{$own->id}/receipt"))->assertOk();
});

it('keeps tenants apart', function () {
    $a = posSalon();
    $b = posSalon();
    posActingAs($a->tenant, $a->owner);
    app(CompleteSale::class)->handle([
        'idempotency_key' => saleKey(), 'branch_id' => $a->branch->id,
        'lines' => [['type' => 'service', 'id' => $a->services[0]->id]], 'payments' => [['method' => 'cash', 'tendered' => 15000]],
    ], $a->owner);

    asTenant($b->tenant, fn () => expect(Sale::count())->toBe(0));
    $sale = asTenant($a->tenant, fn () => Sale::first());
    $this->actingAs($b->owner)->get(tenantUrl($b->tenant, "pos/sales/{$sale->id}/receipt"))->assertNotFound();
});

it('renders the dashboard POS pages for the owner', function () {
    $s = posSalon();
    posActingAs($s->tenant, $s->owner);
    $sale = app(CompleteSale::class)->handle([
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id, 'shift_id' => $s->shift->id, 'client_id' => $s->client->id,
        'lines' => [['type' => 'service', 'id' => $s->services[0]->id, 'staff_id' => $s->layla->id]], 'payments' => [['method' => 'cash', 'tendered' => 15000]],
    ], $s->owner);

    foreach (['dashboard/pos-sales', "dashboard/pos-sales/{$sale->id}", 'dashboard/pos-shifts', "dashboard/pos-shifts/{$s->shift->id}", 'dashboard/pos-registers',
        'dashboard/reports/commissions', 'pos/sales', 'pos/cash-drawer'] as $path) {
        $this->actingAs($s->owner)->get(tenantUrl($s->tenant, $path))->assertOk();
    }
});

it('prints the 80mm receipt, its PDF and the signed public receipt', function () {
    $s = posSalon();
    posActingAs($s->tenant, $s->owner);
    $sale = app(CompleteSale::class)->handle([
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id, 'client_id' => $s->client->id,
        'lines' => [['type' => 'service', 'id' => $s->services[0]->id]], 'payments' => [['method' => 'cash', 'tendered' => 15000]],
    ], $s->owner);
    $publicPath = parse_url($sale->receiptUrl(), PHP_URL_PATH).'?'.parse_url($sale->receiptUrl(), PHP_URL_QUERY);

    $this->actingAs($s->owner)->get(tenantUrl($s->tenant, "pos/sales/{$sale->id}/receipt"))
        ->assertOk()->assertSee('80mm', false)->assertSee($sale->number);
    $pdf = $this->actingAs($s->owner)->get(tenantUrl($s->tenant, "pos/sales/{$sale->id}/receipt.pdf?locale=ar"));
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    auth()->logout();
    $this->get(tenantUrl($s->tenant, $publicPath))->assertOk()->assertSee($sale->number);
    $this->get(tenantUrl($s->tenant, "r/{$sale->id}"))->assertForbidden(); // unsigned
});

it('computes the commission report and exports payroll to Excel', function () {
    Excel::fake();
    $s = posSalon(['tax_percent' => 0]);
    posActingAs($s->tenant, $s->owner);
    app(CompleteSale::class)->handle([
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id,
        'lines' => [
            ['type' => 'service', 'id' => $s->services[0]->id, 'staff_id' => $s->layla->id],  // 150 × 20% = 30.00
            ['type' => 'service', 'id' => $s->services[1]->id, 'staff_id' => $s->mariam->id], // 180 × 10% = 18.00
        ],
        'tips' => [$s->mariam->id => 1000],
        'payments' => [['method' => 'cash', 'tendered' => 34000]],
    ], $s->owner);
    Filament::setCurrentPanel('dashboard');

    $component = Livewire::test(CommissionReport::class)->assertSee('Layla')->assertSee('Mariam');
    $rows = collect($component->instance()->rows)->keyBy('staff');
    expect($rows['Layla']['commission'])->toBe(3000)
        ->and($rows['Mariam']['commission'])->toBe(1800)
        ->and($rows['Mariam']['tips'])->toBe(1000)
        ->and($rows['Mariam']['payout'])->toBe(2800);

    $component->callAction('export');
    $tz = $s->tenant->timezone; // the page defaults its period in the salon timezone
    Excel::assertDownloaded('payroll-'.now($tz)->startOfMonth()->toDateString().'-'.now($tz)->toDateString().'.xlsx');
});
