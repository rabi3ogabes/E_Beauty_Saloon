<?php

require_once __DIR__.'/PosTestHelpers.php';

use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Models\Client;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Domain\POS\Actions\CloseShift;
use App\Domain\POS\Enums\SaleStatus;
use App\Domain\POS\Models\Sale;
use App\Domain\POS\Models\Shift;
use App\Filament\Pos\Pages\Terminal;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('pos');
});

it('renders the terminal as the POS home', function () {
    $s = posSalon();

    $this->actingAs($s->owner)->get(tenantUrl($s->tenant, 'pos'))
        ->assertOk()
        ->assertSee(__('pos.search_placeholder'))
        ->assertSee('Blow-dry')
        ->assertSee('pos/manifest.webmanifest', false);
});

it('adds items, sets the client, pays split and completes from the terminal', function () {
    $s = posSalon(['tax_percent' => 0]);
    posActingAs($s->tenant, $s->owner);

    $component = Livewire::test(Terminal::class)
        ->assertSet('registerId', $s->register->id)
        ->call('addItem', 'service', $s->services[0]->id) // 150.00
        ->call('setTab', 'products')
        ->set('search', $s->products[0]->barcode)
        ->call('scan') // 95.00 by barcode
        ->assertSet('search', '');

    $uid = array_key_first($component->get('lines'));
    $component->set("lines.$uid.staff_id", $s->layla->id)
        ->call('openModal', 'client')
        ->set('clientSearch', '5500')
        ->assertSee('Noura Al-Kuwari')
        ->call('selectClient', $s->client->id)
        ->assertSet('clientId', $s->client->id)
        ->set('tenderAmount', '100')
        ->call('tenderCard')
        ->call('tenderCash', 20000);

    expect($component->get('totals'))->toMatchArray(['total' => 24500, 'remaining' => 0, 'change' => 5500]);

    $component->call('complete')->assertSet('modal', 'receipt')->assertSet('lines', []);

    $sale = Sale::firstOrFail();
    expect($sale->status)->toBe(SaleStatus::Completed)
        ->and($sale->total)->toBe(24500)
        ->and($sale->change_due)->toBe(5500)
        ->and($sale->client_id)->toBe($s->client->id)
        ->and($sale->items->firstWhere('type', 'service')->staff_id)->toBe($s->layla->id)
        ->and($sale->pos_shift_id)->toBe($s->shift->id);
});

it('quick-creates a client by phone', function () {
    $s = posSalon();
    posActingAs($s->tenant, $s->owner);

    Livewire::test(Terminal::class)
        ->set('clientSearch', '3312 4455')
        ->set('newClientName', 'Hessa')
        ->call('quickCreateClient');

    expect(Client::where('phone', '+97433124455')->value('name'))->toBe('Hessa');
});

it('asks receptionists for a manager PIN before a discount', function () {
    $s = posSalon(['tax_percent' => 0]);
    $receptionist = tenantUser($s->tenant, 'receptionist', [$s->branch->id]);
    $manager = tenantUser($s->tenant, 'branch_manager', [$s->branch->id]);
    asTenant($s->tenant, fn () => $manager->setPosPin('2468'));
    posActingAs($s->tenant, $receptionist);

    $component = Livewire::test(Terminal::class)
        ->call('addItem', 'service', $s->services[0]->id)
        ->call('openDiscount')
        ->set('discountType', 'percent')
        ->set('discountInput', '10')
        ->call('applyDiscount')
        ->assertSet('modal', 'pin')
        ->assertSet('ticketDiscount', null)
        ->set('pin', '0000')
        ->call('submitPin')
        ->assertSet('discountApprovedBy', null)
        ->set('pin', '2468')
        ->call('submitPin')
        ->assertSet('discountApprovedBy', $manager->id)
        ->assertSet('ticketDiscount', ['type' => 'percent', 'value' => 1000]);

    expect($component->get('totals')['total'])->toBe(13500);

    $component->call('tenderCash', 13500)->call('complete');
    expect(Sale::firstOrFail()->discount_meta['approved_by'])->toBe($manager->id);
});

it('switches the cashier by PIN without logging out', function () {
    $s = posSalon();
    $receptionist = tenantUser($s->tenant, 'receptionist', [$s->branch->id]);
    asTenant($s->tenant, fn () => $receptionist->setPosPin('1357'));
    posActingAs($s->tenant, $s->owner);

    Livewire::test(Terminal::class)
        ->call('addItem', 'service', $s->services[0]->id)
        ->call('switchUser')
        ->set('pin', '1357')
        ->call('submitPin')
        ->assertSet('modal', null)
        ->assertCount('lines', 1); // the ticket stays on screen

    expect(auth()->id())->toBe($receptionist->id);
});

it('opens a booking from today as a ticket with its deposit', function () {
    $s = posSalon(['tax_percent' => 0]);
    posActingAs($s->tenant, $s->owner);
    $booking = Booking::create([
        'branch_id' => $s->branch->id, 'client_id' => $s->client->id, 'status' => 'checked_in',
        'starts_at' => now(), 'ends_at' => now()->addHour(), 'total' => 15000, 'amount_paid' => 4000,
    ]);
    $booking->items()->create(['service_id' => $s->services[0]->id, 'staff_id' => $s->mariam->id, 'starts_at' => now(), 'ends_at' => now()->addHour(), 'price' => 15000, 'duration_minutes' => 60]);

    $component = Livewire::test(Terminal::class)
        ->call('openModal', 'bookings')
        ->assertSee('Noura Al-Kuwari')
        ->call('loadBooking', $booking->id)
        ->assertSet('bookingId', $booking->id)
        ->assertSet('clientId', $s->client->id)
        ->assertSet('deposit', 4000);

    expect($component->get('totals'))->toMatchArray(['total' => 15000, 'deposit' => 4000, 'remaining' => 11000]);
    $component->call('tenderCash', 11000)->call('complete');

    expect($booking->fresh()->status->value)->toBe('completed');
});

it('takes an online QR payment and completes once it is paid', function () {
    GatewayManager::fake();
    $s = posSalon(['tax_percent' => 0]);
    posActingAs($s->tenant, $s->owner);
    TenantPaymentGateway::create(['driver' => 'skipcash', 'label' => 'SkipCash', 'enabled_for' => ['pos'], 'is_active' => true, 'mode' => 'test']);

    $component = Livewire::test(Terminal::class)
        ->call('addItem', 'service', $s->services[2]->id) // 60.00
        ->call('startOnlinePayment');

    $payment = Payment::firstOrFail();
    expect($payment->status)->toBe('pending')->and($payment->amount)->toBe(6000);
    $component->assertSet('onlinePaymentId', $payment->id)
        ->call('checkOnlinePayment') // fake gateway reports "succeeded"
        ->assertSet('onlinePaymentId', null)
        ->call('complete');

    $sale = Sale::firstOrFail();
    expect($sale->status)->toBe(SaleStatus::Completed)
        ->and($sale->salePayments->first()->payment_id)->toBe($payment->id)
        ->and(Sale::count())->toBe(1);
});

it('refuses to sell before a shift is open', function () {
    $s = posSalon();
    posActingAs($s->tenant, $s->owner);
    app(CloseShift::class)->handle($s->shift, $s->owner, 20000);

    Livewire::test(Terminal::class)
        ->assertSee(__('pos.shift.open_title'))
        ->set('openingFloat', '150')
        ->call('openShift')
        ->assertDontSee(__('pos.shift.open_title'));

    expect(Shift::where('status', 'open')->value('opening_float'))->toBe(15000);
});
