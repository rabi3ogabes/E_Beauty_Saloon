<?php

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\CheckInBooking;
use App\Domain\Booking\Actions\CompleteBooking;
use App\Domain\Booking\Actions\CreateBooking;
use App\Domain\Booking\Actions\CreateGroupBooking;
use App\Domain\Booking\Actions\CreateRecurringBookings;
use App\Domain\Booking\Actions\HoldSlot;
use App\Domain\Booking\Actions\MarkNoShow;
use App\Domain\Booking\Actions\RescheduleBooking;
use App\Domain\Booking\Actions\StartBookingPayment;
use App\Domain\Booking\Actions\StartService;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\Booking\Events\BookingCompleted;
use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\Booking\Events\BookingCreated;
use App\Domain\Booking\Events\BookingNoShow;
use App\Domain\Booking\Events\BookingRescheduled;
use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\BranchHoliday;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Payments\Actions\SettlePayment;
use App\Domain\Payments\Gateways\FakeGateway;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 09:00', 'Asia/Qatar'));
    MessagingManager::fake();
    Model::preventLazyLoading(); // mirror local dev: catch N+1 / lazy loads
    GatewayManager::fake();
});

afterEach(fn () => Model::preventLazyLoading(false));

function book(object $salon, array $data): Booking
{
    return asTenant($salon->tenant, fn () => app(CreateBooking::class)->handle(array_merge(['branch' => $salon->branch], $data)));
}

it('two clients cannot book the same staff slot', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $a = bookingClient($salon->tenant, '+97455550001', 'Aisha');
    $b = bookingClient($salon->tenant, '+97455550002', 'Badria');
    $start = atLocal($salon, '2026-12-01 11:00');
    $items = [['service_id' => $salon->services['cut']->id, 'staff_id' => $salon->staff[0]->id]];

    // Client A holds the slot during checkout; client B's concurrent hold for the same staff/time fails.
    $hold = asTenant($salon->tenant, fn () => app(HoldSlot::class)->handle($salon->branch, $items, $start));
    expect(fn () => asTenant($salon->tenant, fn () => app(HoldSlot::class)->handle($salon->branch, $items, $start)))
        ->toThrow(DomainException::class, __('booking.errors.slot_taken'));
    // ...and B cannot sneak in with a direct booking either ("no preference" too: only one stylist).
    expect(fn () => book($salon, ['items' => [['service_id' => $salon->services['cut']->id]], 'starts_at' => $start, 'client' => $b]))
        ->toThrow(DomainException::class);

    // A books with her hold token; afterwards B still cannot book that slot.
    $booking = book($salon, ['items' => $items, 'starts_at' => $start, 'client' => $a, 'hold_token' => $hold['token']]);
    expect($booking->status)->toBe(BookingStatus::Confirmed);
    expect(fn () => book($salon, ['items' => $items, 'starts_at' => $start->addMinutes(30), 'client' => $b]))->toThrow(DomainException::class);
    expect(asTenant($salon->tenant, fn () => Booking::count()))->toBe(1);
});

it('multi-service multi-staff booking computes correct total time and price', function () {
    $salon = bookingSalon();
    asTenant($salon->tenant, function () use ($salon) {
        $salon->staff[0]->services()->detach($salon->services['color']->id);           // Layla: cut only (+ blow dry)
        $salon->staff[1]->services()->detach($salon->services['cut']->id);             // Sofia: colour only
        $salon->staff[1]->services()->updateExistingPivot($salon->services['color']->id, ['price' => 45000, 'duration_minutes' => 120]); // Sofia: senior colourist
        $salon->services['color']->update(['buffer_after' => 15]);
    });
    $client = bookingClient($salon->tenant);

    $booking = book($salon, ['items' => [['service_id' => $salon->services['cut']->id], ['service_id' => $salon->services['color']->id]],
        'starts_at' => atLocal($salon, '2026-12-01 10:00'), 'client' => $client]);
    $items = $booking->items()->get();

    expect($items)->toHaveCount(2)
        ->and($items[0]->staff_id)->toBe($salon->staff[0]->id)
        ->and($items[1]->staff_id)->toBe($salon->staff[1]->id)
        ->and($items[1]->starts_at->equalTo($items[0]->ends_at))->toBeTrue()
        ->and($booking->starts_at->diffInMinutes($booking->ends_at))->toEqual(180) // 60 + 120
        ->and($booking->total)->toBe(20000 + 45000)
        ->and($items[1]->buffer_after)->toBe(15);
});

it('confirms the booking and records amount_paid when the online deposit is paid', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    onlineGateway($salon->tenant);
    asTenant($salon->tenant, fn () => $salon->services['color']->update(['deposit_type' => 'percent', 'deposit_value' => 3000])); // 30%
    $client = bookingClient($salon->tenant);

    $booking = book($salon, ['items' => [['service_id' => $salon->services['color']->id]], 'starts_at' => atLocal($salon, '2026-12-01 12:00'),
        'client' => $client, 'payment_preference' => 'deposit']);
    expect($booking->status)->toBe(BookingStatus::PendingPayment)->and($booking->deposit_required)->toBe(12000);

    Event::fake([BookingConfirmed::class]);
    $payment = asTenant($salon->tenant, fn () => app(StartBookingPayment::class)->handle($booking, 'deposit', 'https://example.test/done'));
    expect($payment->amount)->toBe(12000)->and($payment->payable_type)->toBe(Booking::class)->and(FakeGateway::$checkouts)->toHaveCount(1);

    asTenant($salon->tenant, fn () => app(SettlePayment::class)->succeed($payment));
    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->amount_paid)->toBe(12000)
        ->and($booking->balanceDue())->toBe(28000)
        ->and($booking->history()->first()->to_status)->toBe(BookingStatus::Confirmed);
    Event::assertDispatched(BookingConfirmed::class);

    // Idempotent: settling again changes nothing.
    asTenant($salon->tenant, fn () => app(SettlePayment::class)->succeed($payment));
    expect($booking->fresh()->amount_paid)->toBe(12000);
});

it('cancels an unpaid booking and frees the slot when the payment fails', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    onlineGateway($salon->tenant);
    $client = bookingClient($salon->tenant);
    $booking = book($salon, ['items' => [['service_id' => $salon->services['cut']->id]], 'starts_at' => atLocal($salon, '2026-12-01 12:00'), 'client' => $client, 'payment_preference' => 'full']);
    $payment = asTenant($salon->tenant, fn () => app(StartBookingPayment::class)->handle($booking, 'full', 'https://example.test/done'));

    asTenant($salon->tenant, fn () => app(SettlePayment::class)->fail($payment, 'declined'));
    expect($booking->fresh()->status)->toBe(BookingStatus::CancelledBySalon)
        ->and(slotTimes($salon, ['cut'], '2026-12-01'))->toContain('12:00');
});

it('applies the late-cancellation fee from the deposit and refunds the rest', function () {
    $salon = bookingSalon(['staff' => ['Layla'], 'settings' => ['cancellation_window_hours' => 24, 'late_cancel_fee_percent' => 50]]);
    onlineGateway($salon->tenant);
    $client = bookingClient($salon->tenant);
    $booking = book($salon, ['items' => [['service_id' => $salon->services['cut']->id]], 'starts_at' => atLocal($salon, '2026-12-01 12:00'), 'client' => $client, 'payment_preference' => 'full']);
    $payment = asTenant($salon->tenant, fn () => app(StartBookingPayment::class)->handle($booking, 'full', 'https://example.test'));
    asTenant($salon->tenant, fn () => app(SettlePayment::class)->succeed($payment));

    Event::fake([BookingCancelled::class]);
    // 27h before = outside the window → full refund.
    $early = asTenant($salon->tenant, fn () => app(CancelBooking::class)->isLate($booking->fresh()));
    expect($early)->toBeFalse();

    $this->travelTo(atLocal($salon, '2026-12-01 08:00')); // 4h before → late
    $cancelled = asTenant($salon->tenant, fn () => app(CancelBooking::class)->handle($booking->fresh(), 'client', 'Changed plans'));

    expect($cancelled->status)->toBe(BookingStatus::CancelledByClient)
        ->and($cancelled->fee_charged)->toBe(10000)
        ->and($cancelled->amount_paid)->toBe(10000)
        ->and(FakeGateway::$refunds[0]['amount'])->toBe(10000)
        ->and($payment->fresh()->status)->toBe('partially_refunded');
    Event::assertDispatched(BookingCancelled::class, fn ($e) => $e->fee === 10000 && $e->refunded === 10000);
});

it('refunds everything when the salon cancels', function () {
    $salon = bookingSalon(['staff' => ['Layla'], 'settings' => ['late_cancel_fee_percent' => 100]]);
    onlineGateway($salon->tenant);
    $booking = book($salon, ['items' => [['service_id' => $salon->services['cut']->id]], 'starts_at' => atLocal($salon, '2026-11-30 12:00'), 'client' => bookingClient($salon->tenant), 'payment_preference' => 'full']);
    $payment = asTenant($salon->tenant, fn () => app(StartBookingPayment::class)->handle($booking, 'full', 'https://example.test'));
    asTenant($salon->tenant, fn () => app(SettlePayment::class)->succeed($payment));

    $cancelled = asTenant($salon->tenant, fn () => app(CancelBooking::class)->handle($booking->fresh(), 'salon', 'Stylist sick'));
    expect($cancelled->status)->toBe(BookingStatus::CancelledBySalon)->and($cancelled->fee_charged)->toBe(0)->and($cancelled->amount_paid)->toBe(0)
        ->and(FakeChannel::sentTo('whatsapp', '+97455550001'))->not->toBeEmpty();
});

it('charges the no-show fee from the deposit', function () {
    $salon = bookingSalon(['staff' => ['Layla'], 'settings' => ['no_show_fee_percent' => 100]]);
    onlineGateway($salon->tenant);
    asTenant($salon->tenant, fn () => $salon->services['color']->update(['deposit_type' => 'fixed', 'deposit_value' => 10000]));
    $client = bookingClient($salon->tenant);
    $booking = book($salon, ['items' => [['service_id' => $salon->services['color']->id]], 'starts_at' => atLocal($salon, '2026-11-30 12:00'), 'client' => $client, 'payment_preference' => 'deposit']);
    $payment = asTenant($salon->tenant, fn () => app(StartBookingPayment::class)->handle($booking, 'deposit', 'https://example.test'));
    asTenant($salon->tenant, fn () => app(SettlePayment::class)->succeed($payment));

    expect(fn () => asTenant($salon->tenant, fn () => app(MarkNoShow::class)->handle($booking->fresh())))->toThrow(DomainException::class); // too early

    Event::fake([BookingNoShow::class]);
    $this->travelTo(atLocal($salon, '2026-11-30 12:30'));
    $noShow = asTenant($salon->tenant, fn () => app(MarkNoShow::class)->handle($booking->fresh()));
    expect($noShow->status)->toBe(BookingStatus::NoShow)->and($noShow->fee_charged)->toBe(10000)->and($noShow->amount_paid)->toBe(10000)
        ->and(FakeGateway::$refunds)->toBe([])
        ->and($client->fresh()->no_shows_count)->toBe(1);
    Event::assertDispatched(BookingNoShow::class);
});

it('enforces the reschedule limit and window for clients', function () {
    $salon = bookingSalon(['staff' => ['Layla'], 'settings' => ['reschedule_limit' => 1, 'cancellation_window_hours' => 24]]);
    $booking = book($salon, ['items' => [['service_id' => $salon->services['cut']->id]], 'starts_at' => atLocal($salon, '2026-12-03 12:00'), 'client' => bookingClient($salon->tenant)]);

    Event::fake([BookingRescheduled::class]);
    $moved = asTenant($salon->tenant, fn () => app(RescheduleBooking::class)->handle($booking, atLocal($salon, '2026-12-04 15:00'), ['by' => 'client']));
    expect($moved->starts_at->equalTo(atLocal($salon, '2026-12-04 15:00')))->toBeTrue()->and($moved->reschedule_count)->toBe(1)
        ->and($moved->items->first()->starts_at->equalTo(atLocal($salon, '2026-12-04 15:00')))->toBeTrue();
    Event::assertDispatched(BookingRescheduled::class);

    expect(fn () => asTenant($salon->tenant, fn () => app(RescheduleBooking::class)->handle($moved, atLocal($salon, '2026-12-05 15:00'), ['by' => 'client'])))
        ->toThrow(DomainException::class, __('booking.errors.reschedule_limit'));
    // The salon may still move it.
    $salonMove = asTenant($salon->tenant, fn () => app(RescheduleBooking::class)->handle($moved, atLocal($salon, '2026-12-05 15:00'), ['by' => 'salon']));
    expect($salonMove->reschedule_count)->toBe(1);

    $late = book($salon, ['items' => [['service_id' => $salon->services['cut']->id]], 'starts_at' => atLocal($salon, '2026-11-30 18:00'), 'client' => bookingClient($salon->tenant, '+97455550009', 'Late')]);
    expect(fn () => asTenant($salon->tenant, fn () => app(RescheduleBooking::class)->handle($late, atLocal($salon, '2026-12-02 15:00'), ['by' => 'client'])))
        ->toThrow(DomainException::class);
});

it('walks through the lifecycle with history and events', function () {
    Event::fake([BookingCreated::class, BookingCompleted::class]);
    $salon = bookingSalon(['staff' => ['Layla']]);
    $booking = book($salon, ['items' => [['service_id' => $salon->services['cut']->id]], 'starts_at' => atLocal($salon, '2026-11-30 12:00'), 'client' => bookingClient($salon->tenant), 'source' => 'dashboard']);

    asTenant($salon->tenant, function () use ($booking) {
        app(CheckInBooking::class)->handle($booking);
        app(StartService::class)->handle($booking);
        app(CompleteBooking::class)->handle($booking);
    });
    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::Completed)->and($booking->checked_in_at)->not->toBeNull()->and($booking->completed_at)->not->toBeNull()
        ->and($booking->history()->pluck('to_status')->map->value->all())->toBe(['completed', 'in_service', 'checked_in', 'confirmed']);
    expect(fn () => asTenant($salon->tenant, fn () => app(CancelBooking::class)->handle($booking, 'salon')))->toThrow(DomainException::class);
    Event::assertDispatched(BookingCreated::class);
    Event::assertDispatched(BookingCompleted::class);
    expect(Activity::where('description', 'booking.completed')->count())->toBe(1);
});

it('creates group bookings sharing a group id', function () {
    $salon = bookingSalon(['staff' => ['Layla', 'Sofia', 'Hana']]);
    $bride = bookingClient($salon->tenant);
    $bookings = asTenant($salon->tenant, fn () => app(CreateGroupBooking::class)->handle(
        ['branch' => $salon->branch, 'starts_at' => atLocal($salon, '2026-12-05 10:00'), 'client' => $bride, 'source' => 'dashboard'],
        [
            ['guest_name' => 'Bride', 'items' => [['service_id' => $salon->services['color']->id]]],
            ['guest_name' => 'Maid 1', 'items' => [['service_id' => $salon->services['cut']->id]]],
            ['guest_name' => 'Maid 2', 'items' => [['service_id' => $salon->services['cut']->id]]],
        ],
    ));

    expect($bookings)->toHaveCount(3)
        ->and($bookings->pluck('group_id')->unique()->all())->toBe([$bookings[0]->id])
        ->and($bookings->map(fn ($b) => $b->items()->first()->staff_id)->unique())->toHaveCount(3)
        ->and($bookings[1]->items()->first()->guest_name)->toBe('Maid 1');

    // A fourth member cannot fit (3 stylists) → nothing is created.
    expect(fn () => asTenant($salon->tenant, fn () => app(CreateGroupBooking::class)->handle(
        ['branch' => $salon->branch, 'starts_at' => atLocal($salon, '2026-12-06 10:00'), 'client' => $bride, 'source' => 'dashboard'],
        array_fill(0, 4, ['items' => [['service_id' => $salon->services['cut']->id]]]),
    )))->toThrow(DomainException::class);
    expect(asTenant($salon->tenant, fn () => Booking::count()))->toBe(3);
});

it('creates recurring bookings every N weeks and skips conflicts', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $client = bookingClient($salon->tenant);
    asTenant($salon->tenant, fn () => BranchHoliday::create(['branch_id' => $salon->branch->id, 'date' => '2026-12-29']));

    $result = asTenant($salon->tenant, fn () => app(CreateRecurringBookings::class)->handle(
        ['branch' => $salon->branch, 'items' => [['service_id' => $salon->services['cut']->id]], 'starts_at' => atLocal($salon, '2026-12-01 11:00'), 'client' => $client, 'source' => 'dashboard'],
        everyWeeks: 4, count: 3,
    ));

    expect($result['bookings'])->toHaveCount(2)->and($result['skipped'])->toBe(['2026-12-29 11:00'])
        ->and($result['bookings'][1]->recurring_parent_id)->toBe($result['bookings'][0]->id)
        ->and($result['bookings'][1]->recurrence_rule)->toBe('every:4:weeks:count:3');
});

it('keeps bookings isolated per tenant', function () {
    $a = bookingSalon(['staff' => ['Layla']]);
    $b = bookingSalon(['staff' => ['Layla']]);
    book($a, ['items' => [['service_id' => $a->services['cut']->id]], 'starts_at' => atLocal($a, '2026-12-01 11:00'), 'client' => bookingClient($a->tenant)]);

    expect(asTenant($b->tenant, fn () => Booking::count()))->toBe(0)
        ->and(slotTimes($b, ['cut'], '2026-12-01'))->toContain('11:00');
});

it('keeps a held slot bookable while the client verifies, even as it crosses the notice window', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $client = bookingClient($salon->tenant);
    $start = atLocal($salon, '2026-12-01 11:00');
    $items = [['service_id' => $salon->services['cut']->id, 'staff_id' => $salon->staff[0]->id]];

    // Picked 61 minutes ahead (notice is 60), then 3 minutes spent on the phone code.
    $this->travelTo($start->subMinutes(61));
    $hold = asTenant($salon->tenant, fn () => app(HoldSlot::class)->handle($salon->branch, $items, $start));
    $this->travelTo($start->subMinutes(58));

    // Without the hold the notice rule still applies...
    expect(fn () => book($salon, ['items' => $items, 'starts_at' => $start, 'client' => bookingClient($salon->tenant, '+97455550009', 'Other')]))
        ->toThrow(DomainException::class);
    // ...but the client who holds it can still book.
    expect(book($salon, ['items' => $items, 'starts_at' => $start, 'client' => $client, 'hold_token' => $hold['token']])->status)
        ->toBe(BookingStatus::Confirmed);
});

it('does not let an expired hold skip the notice window', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $start = atLocal($salon, '2026-12-01 11:00');
    $items = [['service_id' => $salon->services['cut']->id, 'staff_id' => $salon->staff[0]->id]];

    $this->travelTo($start->subMinutes(75));
    $hold = asTenant($salon->tenant, fn () => app(HoldSlot::class)->handle($salon->branch, $items, $start));
    $this->travelTo($start->subMinutes(30)); // hold (10 min) long expired, and inside the notice window

    expect(fn () => book($salon, ['items' => $items, 'starts_at' => $start, 'client' => bookingClient($salon->tenant), 'hold_token' => $hold['token']]))
        ->toThrow(DomainException::class);
});
