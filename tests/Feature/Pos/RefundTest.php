<?php

require_once __DIR__.'/PosTestHelpers.php';

// Production-like strictness: catch N+1 / lazy loading in POS code paths.
beforeEach(fn () => Model::preventLazyLoading());
afterEach(fn () => Model::preventLazyLoading(false));

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\WalletTransaction;
use App\Domain\POS\Actions\CompleteSale;
use App\Domain\POS\Actions\RefundSale;
use App\Domain\POS\Enums\SaleStatus;
use App\Domain\POS\Models\Commission;
use App\Domain\POS\Models\Sale;
use App\Filament\Pos\Resources\Sales\Pages\ListSales;
use App\Support\Exceptions\DomainException;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function productSale(object $s, $cashier = null): Sale
{
    return app(CompleteSale::class)->handle([
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id, 'register_id' => $s->register->id, 'shift_id' => $s->shift->id,
        'client_id' => $s->client->id,
        'lines' => [
            ['type' => 'product', 'id' => $s->products[0]->id, 'qty' => 2, 'staff_id' => $s->layla->id], // 2 × 95.00
            ['type' => 'service', 'id' => $s->services[0]->id, 'staff_id' => $s->layla->id],              // 150.00
        ],
        'payments' => [['method' => 'card_terminal', 'amount' => 34000, 'reference' => 'C-1']],
    ], $cashier ?? $s->owner);
}

it('refunds to store credit, returns stock and reverses commissions', function () {
    $s = posSalon(['tax_percent' => 0]);
    posActingAs($s->tenant, $s->owner);
    $sale = productSale($s);
    $productItem = $sale->items->firstWhere('type', 'product');
    expect(variantStock($s->products[0], $s->branch->id))->toBe(8);

    $refund = app(RefundSale::class)->handle($sale, [$productItem->id => 1], true, 'Wrong shade', $s->owner, $s->owner);

    expect($refund->amount)->toBe(9500)
        ->and($refund->method)->toBe('store_credit')
        ->and($s->client->fresh()->wallet_balance)->toBe(9500)
        ->and(WalletTransaction::where('type', 'store_credit')->sum('amount'))->toBe(9500)
        ->and(variantStock($s->products[0], $s->branch->id))->toBe(9)
        ->and($sale->fresh()->status)->toBe(SaleStatus::PartiallyRefunded)
        ->and($productItem->fresh()->refunded_qty)->toBe(1);

    // product commission 5% of 190.00 = 9.50 → half reversed
    $productCommission = Commission::where('sale_item_id', $productItem->id)->sum('amount');
    expect($productCommission)->toBe(950 - 475);

    expect(Activity::where('description', 'pos.sale.refunded')->exists())->toBeTrue();
});

it('refunds the rest to the original card and closes the sale', function () {
    $s = posSalon(['tax_percent' => 0]);
    posActingAs($s->tenant, $s->owner);
    $sale = productSale($s);

    app(RefundSale::class)->handle($sale, null, false, 'Cancelled', $s->owner, $s->owner);

    $sale->refresh();
    expect($sale->status)->toBe(SaleStatus::Refunded)
        ->and($sale->refunded_total)->toBe(34000)
        ->and(Payment::where('purpose', 'refund')->sum('amount'))->toBe(-34000)
        ->and(Commission::sum('amount'))->toBe(0);
});

it('requires a manager PIN for receptionists to void a sale', function () {
    $s = posSalon(['tax_percent' => 0]);
    $receptionist = tenantUser($s->tenant, 'receptionist', [$s->branch->id]);
    $manager = tenantUser($s->tenant, 'branch_manager', [$s->branch->id]);
    asTenant($s->tenant, fn () => $manager->setPosPin('9753'));
    posActingAs($s->tenant, $receptionist);
    Filament::setCurrentPanel('pos');
    $sale = productSale($s, $receptionist);

    // The action refuses without an authorised approver…
    expect(fn () => app(RefundSale::class)->handle($sale, null, false, 'x', $receptionist, $receptionist, 'void'))
        ->toThrow(DomainException::class, __('pos.errors.manager_pin_required'));

    // …and the POS sales list asks for the manager PIN.
    Livewire::test(ListSales::class)
        ->callTableAction('void', $sale, data: ['reason' => 'Rang twice', 'manager_pin' => '0000'])
        ->assertNotified(__('pos.errors.pin_invalid'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Completed);

    Livewire::test(ListSales::class)
        ->callTableAction('void', $sale, data: ['reason' => 'Rang twice', 'manager_pin' => '9753'])
        ->assertHasNoTableActionErrors();

    $sale->refresh();
    expect($sale->status)->toBe(SaleStatus::Voided)
        ->and($sale->voided_by)->toBe($manager->id)
        ->and(variantStock($s->products[0], $s->branch->id))->toBe(10);
});

it('lets a receptionist refund with a manager PIN from the sales list', function () {
    $s = posSalon(['tax_percent' => 0]);
    $receptionist = tenantUser($s->tenant, 'receptionist', [$s->branch->id]);
    $manager = tenantUser($s->tenant, 'branch_manager', [$s->branch->id]);
    asTenant($s->tenant, fn () => $manager->setPosPin('1122'));
    posActingAs($s->tenant, $receptionist);
    Filament::setCurrentPanel('pos');
    $sale = productSale($s, $receptionist);
    $service = $sale->items->firstWhere('type', 'service');
    $product = $sale->items->firstWhere('type', 'product');

    Livewire::test(ListSales::class)
        ->callTableAction('refund', $sale, data: ['qty' => [$service->id => 1, $product->id => 0], 'method' => 'store_credit', 'reason' => 'Redo', 'manager_pin' => '1122'])
        ->assertHasNoTableActionErrors();

    expect($s->client->fresh()->wallet_balance)->toBe(15000)
        ->and($sale->refunds()->first()->approved_by)->toBe($manager->id);
});
