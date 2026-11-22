<?php

use App\Domain\Booking\Actions\CreateBooking;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Team\Models\TimeOff;
use App\Filament\Dashboard\Pages\StaffCalendar;
use App\Filament\Dashboard\Resources\Bookings\Pages\ListBookings;
use App\Filament\Dashboard\Resources\Bookings\Pages\ViewBooking;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 09:00', 'Asia/Qatar'));
    MessagingManager::fake();
});

function dashboardSalon(): object
{
    $salon = bookingSalon(['staff' => ['Layla', 'Sofia']]);
    $salon->client = bookingClient($salon->tenant, '+97455550001', 'Aisha Client');
    $salon->booking = asTenant($salon->tenant, fn () => app(CreateBooking::class)->handle([
        'branch' => $salon->branch, 'items' => [['service_id' => $salon->services['cut']->id, 'staff_id' => $salon->staff[0]->id]],
        'starts_at' => atLocal($salon, '2026-11-30 12:00'), 'client' => $salon->client, 'source' => 'dashboard', 'online' => false,
    ]));
    $salon->other = asTenant($salon->tenant, fn () => app(CreateBooking::class)->handle([
        'branch' => $salon->branch, 'items' => [['service_id' => $salon->services['color']->id, 'staff_id' => $salon->staff[1]->id]],
        'starts_at' => atLocal($salon, '2026-11-30 14:00'), 'client' => bookingClient($salon->tenant, '+97455550002', 'Badria Other'), 'source' => 'dashboard', 'online' => false,
    ]));

    return $salon;
}

function asDashboard(object $salon, $user): void
{
    test()->actingAs($user);
    app(TenantContext::class)->set($salon->tenant);
    Filament::setCurrentPanel('dashboard');
}

it('calendar page renders for owner and stylist (own column only)', function () {
    $salon = dashboardSalon();

    $this->actingAs($salon->tenant->owner)->get(tenantUrl($salon->tenant, 'dashboard/calendar?date=2026-11-30'))
        ->assertOk()->assertSee('Layla')->assertSee('Sofia')->assertSee('Aisha Client')->assertSee('Badria Other');

    $stylist = tenantUser($salon->tenant, 'stylist', [$salon->branch->id]);
    asTenant($salon->tenant, fn () => $salon->staff[1]->update(['user_id' => $stylist->id]));
    $this->flushSession();

    $this->actingAs($stylist)->get(tenantUrl($salon->tenant, 'dashboard/calendar?date=2026-11-30'))
        ->assertOk()->assertSee('Badria Other')->assertDontSee('Aisha Client')->assertDontSee('Layla');
    $this->actingAs($stylist)->get(tenantUrl($salon->tenant, 'dashboard/bookings'))->assertOk();
    $this->actingAs($stylist)->get(tenantUrl($salon->tenant, 'dashboard/bookings/'.$salon->booking->id))->assertNotFound();
});

it('renders the bookings list, booking page and today widget', function () {
    $salon = dashboardSalon();
    $owner = $salon->tenant->owner;

    $this->actingAs($owner)->get(tenantUrl($salon->tenant, 'dashboard/bookings'))->assertOk()->assertSee($salon->booking->reference);
    $this->actingAs($owner)->get(tenantUrl($salon->tenant, 'dashboard/bookings/'.$salon->booking->id))->assertOk()->assertSee('Aisha Client')->assertSee(__('booking.admin.history'));
    $this->actingAs($owner)->get(tenantUrl($salon->tenant, 'dashboard'))->assertOk()->assertSee(__('booking.widget.title'));
});

it('re-validates drag-and-drop moves and resizes on the server', function () {
    $salon = dashboardSalon();
    asDashboard($salon, $salon->tenant->owner);
    $item = $salon->booking->items()->first();

    // Move Layla's 12:00 cut to Sofia at 16:00.
    Livewire::test(StaffCalendar::class, ['date' => '2026-11-30'])->call('moveItem', $item->id, $salon->staff[1]->id, '2026-11-30', 16 * 60);
    $item->refresh();
    expect($item->staff_id)->toBe($salon->staff[1]->id)->and($item->starts_at->toIso8601ZuluString())->toBe(atLocal($salon, '2026-11-30 16:00')->toIso8601ZuluString());

    // Moving onto Sofia's 14:00 colour (14:00–15:30) is refused and nothing changes.
    Livewire::test(StaffCalendar::class)->call('moveItem', $item->id, $salon->staff[1]->id, '2026-11-30', 14 * 60 + 30);
    expect($item->fresh()->starts_at->toIso8601ZuluString())->toBe(atLocal($salon, '2026-11-30 16:00')->toIso8601ZuluString());

    // Resize to 90 minutes.
    Livewire::test(StaffCalendar::class)->call('resizeItem', $item->id, 90);
    expect($item->fresh()->duration_minutes)->toBe(90)->and($salon->booking->fresh()->ends_at->toIso8601ZuluString())->toBe(atLocal($salon, '2026-11-30 17:30')->toIso8601ZuluString());
});

it('checks in, adds walk-ins, quick-creates and blocks time from the calendar', function () {
    $salon = dashboardSalon();
    asDashboard($salon, $salon->tenant->owner);

    Livewire::test(StaffCalendar::class)->call('checkIn', $salon->booking->id);
    expect($salon->booking->fresh()->status)->toBe(BookingStatus::CheckedIn);

    $this->travelTo(atLocal($salon, '2026-11-30 11:02')); // a walk-in during opening hours

    Livewire::test(StaffCalendar::class)
        ->callAction('walkIn', data: ['branch_id' => $salon->branch->id, 'client_id' => $salon->client->id, 'items' => [['service_id' => $salon->services['blowdry']->id, 'staff_id' => null]]])
        ->assertHasNoActionErrors();
    $walkIn = asTenant($salon->tenant, fn () => Booking::where('source', 'walk_in')->first());
    expect($walkIn->status)->toBe(BookingStatus::CheckedIn);

    Livewire::test(StaffCalendar::class)
        ->callAction('quickCreate', data: ['branch_id' => $salon->branch->id, 'client_id' => $salon->client->id, 'starts_at' => '2026-11-30 18:00',
            'items' => [['service_id' => $salon->services['cut']->id, 'staff_id' => $salon->staff[0]->id]]], arguments: ['staff' => $salon->staff[0]->id, 'date' => '2026-11-30', 'time' => '18:00'])
        ->assertHasNoActionErrors();
    expect(asTenant($salon->tenant, fn () => Booking::where('starts_at', atLocal($salon, '2026-11-30 18:00'))->exists()))->toBeTrue();

    Livewire::test(StaffCalendar::class)
        ->callAction('blockTime', data: ['staff_id' => $salon->staff[0]->id, 'date' => '2026-12-01', 'from' => '13:00', 'to' => '15:00', 'reason' => 'Training'])
        ->assertHasNoActionErrors();
    $block = asTenant($salon->tenant, fn () => TimeOff::where('type', 'block')->first());
    expect($block->status)->toBe('approved')->and(slotTimes($salon, [['service' => 'cut', 'staff_id' => $salon->staff[0]->id]], '2026-12-01'))->not->toContain('13:00');
});

it('runs status actions from the bookings table', function () {
    $salon = dashboardSalon();
    asDashboard($salon, $salon->tenant->owner);

    Livewire::test(ListBookings::class)->callTableAction('cancelBooking', $salon->other, data: ['reason' => 'Stylist unwell'])->assertHasNoTableActionErrors();
    expect($salon->other->fresh()->status)->toBe(BookingStatus::CancelledBySalon);

    Livewire::test(ViewBooking::class, ['record' => $salon->booking->id])->callAction('checkIn');
    expect($salon->booking->fresh()->status)->toBe(BookingStatus::CheckedIn);
});

it('hides the booking menu and blocks the calendar when the module is disabled', function () {
    $salon = dashboardSalon();
    $sub = $salon->tenant->currentSubscription;
    $sub->update(['modules' => array_values(array_diff($sub->modules, ['booking']))]);
    Feature::flushCache();

    $this->actingAs($salon->tenant->owner)->get(tenantUrl($salon->tenant, 'dashboard/calendar'))->assertForbidden();
    $this->actingAs($salon->tenant->owner)->get(tenantUrl($salon->tenant, 'dashboard/bookings'))->assertForbidden();
    $this->actingAs($salon->tenant->owner)->get(tenantUrl($salon->tenant, 'dashboard'))->assertOk()
        ->assertDontSee(__('booking.calendar_page.title'))->assertDontSee(__('booking.widget.title'));
});
