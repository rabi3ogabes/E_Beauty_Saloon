<?php

require_once __DIR__.'/WorkspaceHelpers.php';

use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Actions\CreatePurchaseOrder;
use App\Domain\Inventory\Actions\MarkPurchaseOrderOrdered;
use App\Domain\Inventory\Actions\ReceivePurchaseOrder;
use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Inventory\Models\BranchStock;
use App\Domain\Inventory\Models\PurchaseOrder;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\Supplier;
use App\Filament\Dashboard\Resources\PurchaseOrders\Pages\CreatePurchaseOrder as CreatePurchaseOrderPage;
use App\Filament\Dashboard\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Dashboard\Resources\Stock\BranchStockResource;
use App\Filament\Dashboard\Resources\Stock\Pages\ManageBranchStock;
use App\Filament\Dashboard\Resources\StockTransfers\Pages\ManageStockTransfers;
use App\Support\Exceptions\DomainException;
use Livewire\Livewire;

function ws_qty(int $branchId, int $variantId): int
{
    return (int) BranchStock::withoutGlobalScopes()->where('branch_id', $branchId)->where('product_variant_id', $variantId)->value('qty');
}

it('stock changes only via the ledger: adjustments write movements that add up to the balance', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);
    $v = ws_variant();
    $main = ws_main_branch();

    app(AdjustStock::class)->handle($main->id, $v->id, 10, 'delta', 'Opening count');
    app(AdjustStock::class)->handle($main->id, $v->id, -3, 'delta', 'Damaged');
    $m = app(AdjustStock::class)->handle($main->id, $v->id, 4, 'set', 'Shelf count');

    expect(ws_qty($main->id, $v->id))->toBe(4)
        ->and($m->qty)->toBe(-3)->and($m->balance_after)->toBe(4)->and($m->type)->toBe('adjustment')
        ->and((int) StockMovement::where('product_variant_id', $v->id)->sum('qty'))->toBe(4)
        ->and(app(AdjustStock::class)->handle($main->id, $v->id, 4, 'set'))->toBeNull();

    expect(fn () => app(AdjustStock::class)->handle($main->id, $v->id, -50))->toThrow(DomainException::class);
    expect(ws_qty($main->id, $v->id))->toBe(4);
});

it('adjusts stock from the stock levels page and flags low stock', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);
    $v = ws_variant();
    $main = ws_main_branch();

    Livewire::test(ManageBranchStock::class)
        ->callAction('adjustStock', data: ['branch_id' => $main->id, 'product_variant_id' => $v->id, 'mode' => 'delta', 'qty' => 2, 'note' => 'Delivery'])
        ->assertHasNoActionErrors();

    $row = BranchStock::firstOrFail();
    expect($row->qty)->toBe(2)->and($row->isLow())->toBeTrue()
        ->and(BranchStockResource::getNavigationBadge())->toBe('1');

    Livewire::test(ManageBranchStock::class)
        ->filterTable('low', true)->assertCanSeeTableRecords([$row])
        ->callTableAction('threshold', $row, data: ['low_stock_threshold' => 1]);
    expect($row->fresh()->low_stock_threshold)->toBe(1)->and($row->fresh()->qty)->toBe(2);
});

it('transfers stock between branches atomically', function () {
    $tenant = createTenant(branches: 2);
    ws_owner_session($this, $tenant);
    $pearl = ws_branch('pearl');
    $main = ws_main_branch();
    $a = ws_variant('A');
    $b = ws_variant('B');
    app(StockLedger::class)->move($main->id, $a->id, 10, 'purchase');
    app(StockLedger::class)->move($main->id, $b->id, 1, 'purchase');

    Livewire::test(ManageStockTransfers::class)
        ->callAction('newTransfer', data: ['from_branch_id' => $main->id, 'to_branch_id' => $pearl->id, 'lines' => [['product_variant_id' => $a->id, 'qty' => 4]]])
        ->assertHasNoActionErrors();
    expect(ws_qty($main->id, $a->id))->toBe(6)->and(ws_qty($pearl->id, $a->id))->toBe(4)->and(StockTransfer::count())->toBe(1);

    // Second line fails (only 1 of B): nothing moves, no transfer is recorded.
    Livewire::test(ManageStockTransfers::class)
        ->callAction('newTransfer', data: ['from_branch_id' => $main->id, 'to_branch_id' => $pearl->id, 'lines' => [
            ['product_variant_id' => $a->id, 'qty' => 2], ['product_variant_id' => $b->id, 'qty' => 5],
        ]]);

    expect(ws_qty($main->id, $a->id))->toBe(6)->and(ws_qty($pearl->id, $a->id))->toBe(4)
        ->and(ws_qty($main->id, $b->id))->toBe(1)->and(StockTransfer::count())->toBe(1)
        ->and(StockMovement::where('type', 'transfer_out')->count())->toBe(1);
});

it('creates a purchase order, marks it ordered and receives it in two deliveries', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);
    $v = ws_variant('PO-1', cost: 4000);
    $supplier = Supplier::create(['name' => 'Beauty Distribution WLL']);
    $main = ws_main_branch();

    Livewire::test(CreatePurchaseOrderPage::class)
        ->fillForm(['branch_id' => $main->id, 'supplier_id' => $supplier->id, 'items' => [['product_variant_id' => $v->id, 'qty' => 10, 'unit_cost' => '40']]])
        ->call('create')->assertHasNoFormErrors();

    $po = PurchaseOrder::firstOrFail();
    expect($po->status)->toBe('draft')->and($po->total)->toBe(40000)->and(ws_qty($main->id, $v->id))->toBe(0);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $po->id])->callAction('markOrdered')->assertHasNoActionErrors();
    expect($po->fresh()->status)->toBe('ordered');

    $item = $po->items()->first();
    Livewire::test(ViewPurchaseOrder::class, ['record' => $po->id])
        ->callAction('receive', data: ['lines' => [$item->id => 6]])->assertHasNoActionErrors();
    expect($po->fresh()->status)->toBe('partially_received')->and(ws_qty($main->id, $v->id))->toBe(6);

    app(ReceivePurchaseOrder::class)->handle($po->fresh());
    expect($po->fresh()->status)->toBe('received')->and(ws_qty($main->id, $v->id))->toBe(10)
        ->and(StockMovement::where('type', 'purchase')->sum('qty'))->toEqual(10);

    expect(fn () => app(MarkPurchaseOrderOrdered::class)->handle($po->fresh()))->toThrow(DomainException::class);
    expect(fn () => app(CreatePurchaseOrder::class)->handle($main->id, null, []))->toThrow(DomainException::class);
});
