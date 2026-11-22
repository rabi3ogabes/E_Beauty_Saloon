<?php

require_once __DIR__.'/PosTestHelpers.php';

// Production-like strictness: catch N+1 / lazy loading in POS code paths.
beforeEach(fn () => Model::preventLazyLoading());
afterEach(fn () => Model::preventLazyLoading(false));

use App\Domain\POS\Actions\CloseShift;
use App\Domain\POS\Actions\CompleteSale;
use App\Domain\POS\Actions\OpenShift;
use App\Domain\POS\Actions\RecordCashMovement;
use App\Domain\POS\Actions\RefundSale;
use App\Domain\POS\Models\Sale;
use App\Filament\Pos\Pages\CashDrawer;
use App\Support\Exceptions\DomainException;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function cashSale(object $s, int $amountTendered, array $lines): Sale
{
    return app(CompleteSale::class)->handle([
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id, 'register_id' => $s->register->id, 'shift_id' => $s->shift->id,
        'client_id' => $s->client->id, 'lines' => $lines, 'payments' => [['method' => 'cash', 'tendered' => $amountTendered]],
    ], $s->owner);
}

it('shows the correct expected cash at shift close including refunds and cash-outs', function () {
    Storage::fake('local');
    $s = posSalon(['tax_percent' => 0]);
    posActingAs($s->tenant, $s->owner);

    $a = cashSale($s, 20000, [['type' => 'service', 'id' => $s->services[0]->id]]);      // 150.00 cash (50.00 change)
    cashSale($s, 6000, [['type' => 'service', 'id' => $s->services[2]->id]]);            // 60.00 cash
    app(CompleteSale::class)->handle([                                                   // card only — not in the drawer
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id, 'shift_id' => $s->shift->id,
        'lines' => [['type' => 'service', 'id' => $s->services[1]->id]], 'payments' => [['method' => 'card_terminal', 'amount' => 18000]],
    ], $s->owner);
    app(RefundSale::class)->handle($a, null, false, 'Client unhappy', $s->owner, $s->owner, 'refund', $s->shift); // −150.00 cash
    app(RecordCashMovement::class)->handle($s->shift, $s->owner, 'out', 3000, 'Flowers');                   // −30.00
    app(RecordCashMovement::class)->handle($s->shift, $s->owner, 'in', 1000, 'Change top-up');              // +10.00

    // expected = 200 float + 210 cash sales − 150 refund − 30 out + 10 in = 240.00
    $shift = app(CloseShift::class)->handle($s->shift, $s->owner, 23500, 'Short 5');

    expect($shift->status)->toBe('closed')
        ->and($shift->expected_cash)->toBe(24000)
        ->and($shift->counted_cash)->toBe(23500)
        ->and($shift->variance)->toBe(-500)
        ->and($shift->summary['cash_refunds'])->toBe(15000)
        ->and($shift->summary['by_method']['card_terminal']['total'])->toBe(18000);
    Storage::disk('local')->assertExists($shift->z_report_path);
});

it('renders the Z-report PDF in English and Arabic', function () {
    $s = posSalon();
    posActingAs($s->tenant, $s->owner);
    cashSale($s, 15000, [['type' => 'service', 'id' => $s->services[0]->id]]);
    $shift = app(CloseShift::class)->handle($s->shift, $s->owner, 35000);

    $en = $this->actingAs($s->owner)->get(tenantUrl($s->tenant, "pos/shifts/{$shift->id}/z-report"));
    $en->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($en->getContent(), 0, 4))->toBe('%PDF');

    $ar = $this->actingAs($s->owner)->get(tenantUrl($s->tenant, "pos/shifts/{$shift->id}/z-report?locale=ar"));
    expect(substr($ar->getContent(), 0, 4))->toBe('%PDF');
});

it('allows only one open shift per register', function () {
    $s = posSalon();
    posActingAs($s->tenant, $s->owner);

    expect(fn () => app(OpenShift::class)->handle($s->register, $s->owner, 0))->toThrow(DomainException::class, __('pos.errors.shift_already_open'));
});

it('needs a manager PIN for a receptionist to close a shift', function () {
    $s = posSalon();
    $receptionist = tenantUser($s->tenant, 'receptionist', [$s->branch->id]);
    $manager = tenantUser($s->tenant, 'branch_manager', [$s->branch->id]);
    asTenant($s->tenant, fn () => $manager->setPosPin('8642'));
    posActingAs($s->tenant, $receptionist);
    Filament::setCurrentPanel('pos');

    expect(fn () => app(CloseShift::class)->handle($s->shift, $receptionist, 20000))->toThrow(DomainException::class);

    Livewire::test(CashDrawer::class)
        ->callAction('cashOut', data: ['amount' => '25.00', 'reason' => 'Coffee'])
        ->assertHasNoActionErrors()
        ->callAction('closeShift', data: ['counted' => '175.00', 'manager_pin' => '8642'])
        ->assertHasNoActionErrors();

    expect($s->shift->fresh())->status->toBe('closed')->variance->toBe(0)->closed_by->toBe($receptionist->id);
});
