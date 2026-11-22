<?php

use App\Domain\Messaging\MessagingManager;
use App\Domain\Shop\Actions\PlaceOrder;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Support\ShopSettings;
use App\Filament\Dashboard\Pages\Shop\OrdersBoard;
use App\Filament\Dashboard\Pages\Shop\ShopSettings as ShopSettingsPage;
use App\Filament\Dashboard\Resources\Shop\Orders\Pages\ViewOrder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Shop\ShopKit;

beforeEach(function () {
    MessagingManager::fake();
    Filament::setCurrentPanel('dashboard');
});

function shopOrder($tenant, string $fulfilment = 'pickup')
{
    $v = ShopKit::variant($tenant, ['price' => 10000], stock: 5);
    $client = ShopKit::client($tenant, '+9745555'.random_int(1000, 9999));
    $data = $fulfilment === 'pickup'
        ? ['fulfilment' => 'pickup', 'branch_id' => ShopKit::branch($tenant)->id, 'payment_method' => 'cod']
        : ['fulfilment' => 'delivery', 'zone_id' => ShopKit::zone($tenant, ['min_order' => 0])->id, 'area' => 'West Bay', 'payment_method' => 'cod'];

    return asTenant($tenant, fn () => app(PlaceOrder::class)->handle(ShopKit::cart($tenant, $client, [[$v, 1]]), $data, $client));
}

it('renders every shop dashboard page for the owner', function () {
    $tenant = createTenant();
    $order = shopOrder($tenant, 'delivery');
    $this->actingAs($tenant->owner);

    foreach (['dashboard/shop/board', 'dashboard/shop/orders', "dashboard/shop/orders/{$order->number}", 'dashboard/shop/delivery-zones', 'dashboard/shop/drivers',
        'dashboard/shop/shop-coupons', 'dashboard/shop/staff-picks', 'dashboard/shop/settings', 'dashboard/shop/analytics'] as $path) {
        $this->get(tenantUrl($tenant, $path))->assertOk();
    }
    $this->get(tenantUrl($tenant, 'dashboard/shop/board'))->assertSee($order->number)->assertSee(__('shop.status.pending'));
    $this->get(tenantUrl($tenant, 'dashboard'))->assertOk()->assertSee(__('shop.admin.board.title'));
});

it('module disabled → menu hidden and shop pages blocked', function () {
    $tenant = createTenant('essential');
    $this->actingAs($tenant->owner);

    $this->get(tenantUrl($tenant, 'dashboard'))->assertOk()->assertDontSee(__('shop.admin.board.title'))->assertDontSee(__('shop.admin.orders.plural'));
    $this->get(tenantUrl($tenant, 'dashboard/shop/board'))->assertForbidden();
    $this->get(tenantUrl($tenant, 'dashboard/shop/orders'))->assertForbidden();
    $this->get(tenantUrl($tenant, 'dashboard/shop/settings'))->assertForbidden();
});

it('kanban move triggers actions', function () {
    $tenant = createTenant();
    $order = shopOrder($tenant, 'delivery');
    $this->actingAs($tenant->owner);
    asTenant($tenant);

    Livewire::test(OrdersBoard::class)
        ->call('moveOrder', $order->id, 'confirmed')->assertNotified(__('shop.admin.board.moved', ['number' => $order->number, 'status' => OrderStatus::Confirmed->label()]));
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)->and($order->fresh()->stock_state)->toBe('deducted');

    // Invalid move (confirmed → returned) bounces with the validation message and changes nothing.
    Livewire::test(OrdersBoard::class)->call('moveOrder', $order->id, 'returned')
        ->assertNotified(__('shop.errors.transition', ['from' => OrderStatus::Confirmed->label(), 'to' => OrderStatus::Returned->label()]));
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);

    // Pickup-only column refuses a delivery order.
    Livewire::test(OrdersBoard::class)->call('moveOrder', $order->id, 'ready_for_pickup');
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);

    Livewire::test(OrdersBoard::class)->call('moveOrder', $order->id, 'out_for_delivery')->call('moveOrder', $order->id, 'delivered');
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Delivered)->and($order->paid)->toBe($order->total)
        ->and($order->history()->pluck('to_status')->all())->toBe(['pending', 'confirmed', 'out_for_delivery', 'delivered']);

    Livewire::test(OrdersBoard::class)->call('moveOrder', $order->id, 'returned');
    expect($order->fresh()->status)->toBe(OrderStatus::Returned);
});

it('lets view-only staff see the board but not move orders', function () {
    $tenant = createTenant();
    $order = shopOrder($tenant);
    $receptionist = tenantUser($tenant, 'receptionist');
    $this->actingAs($receptionist);
    asTenant($tenant);

    $this->get(tenantUrl($tenant, 'dashboard/shop/board'))->assertOk();
    $this->get(tenantUrl($tenant, 'dashboard/shop/settings'))->assertForbidden();
    Livewire::test(OrdersBoard::class)->call('moveOrder', $order->id, 'confirmed');
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

it('runs order resource actions and saves shop settings', function () {
    $tenant = createTenant();
    $order = shopOrder($tenant);
    $this->actingAs($tenant->owner);
    asTenant($tenant);

    Livewire::test(ViewOrder::class, ['record' => $order->number])->callAction('confirm')->assertHasNoActionErrors();
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    Livewire::test(ViewOrder::class, ['record' => $order->number])->callAction('cancel', data: ['reason' => 'out_of_stock', 'store_credit' => false])->assertHasNoActionErrors();
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)->and($order->fresh()->cancel_reason)->toBe('out_of_stock');

    Livewire::test(ShopSettingsPage::class)
        ->fillForm(['cod_enabled' => true, 'cod_limit' => '150.00', 'cod_otp_required' => true, 'gift_wrap_enabled' => true, 'gift_wrap_price' => '30', 'same_day_cutoff' => '13:30',
            'pickup_prep_minutes' => 90, 'hide_out_of_stock' => true, 'low_stock_badge' => 2, 'reservation_minutes' => 20, 'abandoned_after_minutes' => 180])
        ->call('save')->assertHasNoFormErrors();

    $settings = ShopSettings::all($tenant->fresh());
    expect($settings['cod_limit'])->toBe(15000)->and($settings['gift_wrap_price'])->toBe(3000)->and($settings['same_day_cutoff'])->toBe('13:30')
        ->and($settings['cod_otp_required'])->toBeTrue()->and($settings['hide_out_of_stock'])->toBeTrue();
});
