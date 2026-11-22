<?php

use App\Domain\Booking\Actions\CreateBooking;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\Booking\Events\BookingCreated;
use App\Domain\Booking\Events\BookingRescheduled;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SlotHold;
use App\Domain\Branches\Models\BranchHoliday;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Payments\Gateways\GatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Booking/Helpers.php';
require_once __DIR__.'/ApiHelpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 09:00', 'Asia/Qatar'));
    MessagingManager::fake();
    GatewayManager::fake();
    $this->salon = bookingSalon(['staff' => ['Layla']]);
    $this->client = bookingClient($this->salon->tenant);
    $this->h = apiHeaders($this->salon->tenant, clientToken($this->client));
});

function bookingBody(object $salon, string $service = 'cut', string $startsAt = '2026-12-01T08:00:00Z', array $extra = []): array
{
    return array_merge(['branch_id' => $salon->branch->id, 'items' => [['service_id' => $salon->services[$service]->id]], 'starts_at' => $startsAt], $extra);
}

it('serves availability grouped by part of day in the branch timezone, with ETag and next available date', function () {
    $q = http_build_query(['branch_id' => $this->salon->branch->id, 'services' => [$this->salon->services['cut']->id], 'date' => '2026-12-01']);
    $res = $this->getJson(apiUrl('availability?'.$q), apiHeaders($this->salon->tenant))
        ->assertOk()
        ->assertJsonPath('data.date', '2026-12-01')
        ->assertJsonPath('data.timezone', 'Asia/Qatar')
        ->assertJsonPath('data.groups.morning.0.starts_at', '2026-12-01T07:00:00Z')
        ->assertJsonPath('data.groups.morning.0.time', '10:00')
        ->assertJsonPath('data.groups.morning.0.items.0.service_id', $this->salon->services['cut']->id)
        ->assertJsonPath('data.groups.morning.0.items.0.staff_name', 'Layla')
        ->assertJsonPath('data.groups.morning.0.price.amount', 20000)
        ->assertJsonPath('data.payment_options', ['pay_at_salon']);
    expect($res->json('data.count'))->toBe(45)->and($res->json('data.groups.evening'))->not->toBeEmpty();

    $this->getJson(apiUrl('availability?'.$q), apiHeaders($this->salon->tenant, extra: ['If-None-Match' => $res->headers->get('ETag')]))->assertStatus(304);

    asTenant($this->salon->tenant, fn () => BranchHoliday::create(['branch_id' => $this->salon->branch->id, 'date' => '2026-12-02']));
    $q2 = http_build_query(['branch_id' => $this->salon->branch->id, 'services' => [$this->salon->services['cut']->id], 'date' => '2026-12-02']);
    $this->getJson(apiUrl('availability?'.$q2), apiHeaders($this->salon->tenant))
        ->assertOk()->assertJsonPath('data.count', 0)->assertJsonPath('data.next_available_date', '2026-12-03');
});

it('validates availability input with the shared error envelope', function () {
    $this->getJson(apiUrl('availability'), apiHeaders($this->salon->tenant))
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonValidationErrors(['date', 'branch_id']);

    $q = http_build_query(['branch_id' => 999, 'services' => [$this->salon->services['cut']->id], 'date' => '2026-12-01']);
    $this->getJson(apiUrl('availability?'.$q), apiHeaders($this->salon->tenant))
        ->assertStatus(422)->assertJsonPath('error.code', 'domain_error')->assertJsonPath('message', __('booking.errors.branch_unavailable'));
});

it('books pay-at-salon end to end, lists, shows, reschedules and cancels', function () {
    $res = $this->postJson(apiUrl('bookings'), bookingBody($this->salon, extra: ['notes' => 'Window seat please']), $this->h)
        ->assertCreated()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.source', 'app')
        ->assertJsonPath('data.local.time', '11:00')
        ->assertJsonPath('data.items.0.staff_name', 'Layla')
        ->assertJsonPath('data.total.amount', 20000)
        ->assertJsonPath('data.payment.preference', 'pay_at_salon')
        ->assertJsonPath('data.payment.checkout_url', null)
        ->assertJsonPath('data.notes', 'Window seat please')
        ->assertJsonPath('data.can_cancel', true);
    $reference = $res->json('data.reference');
    expect(asTenant($this->salon->tenant, fn () => SlotHold::whereNull('booking_id')->count()))->toBe(0);

    $this->getJson(apiUrl('bookings?scope=upcoming'), $this->h)->assertOk()->assertJsonPath('data.0.reference', $reference)->assertJsonPath('meta.total', 1);
    $this->getJson(apiUrl("bookings/{$reference}"), $this->h)->assertOk()->assertJsonPath('data.reference', $reference);

    $this->getJson(apiUrl("bookings/{$reference}/availability?date=2026-12-02"), $this->h)->assertOk()->assertJsonPath('data.groups.morning.0.time', '10:00');
    $this->postJson(apiUrl("bookings/{$reference}/reschedule"), ['starts_at' => '2026-12-02T09:00:00Z'], $this->h)
        ->assertOk()->assertJsonPath('data.local.date', '2026-12-02')->assertJsonPath('data.local.time', '12:00')->assertJsonPath('data.reschedules_left', 1);

    $this->postJson(apiUrl("bookings/{$reference}/cancel"), ['reason' => 'Travelling'], $this->h)
        ->assertOk()->assertJsonPath('data.status', 'cancelled_by_client')->assertJsonPath('data.can_cancel', false);
    $this->postJson(apiUrl("bookings/{$reference}/cancel"), [], $this->h)->assertStatus(422)->assertJsonPath('message', __('api.booking.cannot_cancel'));
    $this->getJson(apiUrl('bookings?scope=past'), $this->h)->assertOk()->assertJsonPath('data.0.reference', $reference);
});

it('returns the hosted payment page for a deposit and confirms after the gateway settles', function () {
    onlineGateway($this->salon->tenant);
    asTenant($this->salon->tenant, fn () => $this->salon->services['color']->update(['deposit_type' => 'percent', 'deposit_value' => 2500]));

    $hold = $this->postJson(apiUrl('bookings/hold'), bookingBody($this->salon, 'color', '2026-12-01T09:00:00Z'), $this->h)
        ->assertCreated()->assertJsonPath('data.deposit.amount', 10000)->assertJsonPath('data.payment_options', ['deposit', 'full']);

    $res = $this->postJson(apiUrl('bookings'), bookingBody($this->salon, 'color', '2026-12-01T09:00:00Z', ['payment_preference' => 'deposit', 'hold_token' => $hold->json('data.hold_token')]), $this->h)
        ->assertCreated()->assertJsonPath('data.status', 'pending_payment')->assertJsonPath('data.payment.status', 'awaiting_payment')
        ->assertJsonPath('data.payment.deposit_required.amount', 10000);
    $checkout = $res->json('data.payment.checkout_url');
    expect($checkout)->toContain('/payments/fake/');

    $key = basename(parse_url($checkout, PHP_URL_PATH));
    $this->post(tenantUrl($this->salon->tenant, 'payments/fake/'.$key), ['outcome' => 'success'])->assertRedirect();
    $this->get(tenantUrl($this->salon->tenant, 'payments/return/'.$key));

    $this->getJson(apiUrl('bookings/'.$res->json('data.reference')), $this->h)
        ->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.payment.amount_paid.amount', 10000)
        ->assertJsonPath('data.payment.status', 'partially_paid')->assertJsonPath('data.payment.checkout_url', null);
});

it('rejects a payment option the salon does not offer and a taken slot', function () {
    $this->postJson(apiUrl('bookings'), bookingBody($this->salon, extra: ['payment_preference' => 'full']), $this->h)
        ->assertStatus(422)->assertJsonPath('message', __('booking.errors.payment_option'));
    expect(asTenant($this->salon->tenant, fn () => SlotHold::count()))->toBe(0); // own hold released

    $this->postJson(apiUrl('bookings'), bookingBody($this->salon), $this->h)->assertCreated();
    $this->postJson(apiUrl('bookings'), bookingBody($this->salon), $this->h)
        ->assertStatus(422)->assertJsonPath('message', __('booking.errors.slot_taken'))->assertJsonPath('error.code', 'domain_error');
});

it('is idempotent with the Idempotency-Key header', function () {
    $h = $this->h + ['Idempotency-Key' => 'book-123'];
    $first = $this->postJson(apiUrl('bookings'), bookingBody($this->salon), $h)->assertCreated();
    $again = $this->postJson(apiUrl('bookings'), bookingBody($this->salon), $h)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data.reference'))->toBe($first->json('data.reference'))
        ->and(asTenant($this->salon->tenant, fn () => Booking::count()))->toBe(1);

    $this->postJson(apiUrl('bookings'), bookingBody($this->salon, 'color'), $h)->assertStatus(422)->assertJsonPath('message', __('api.errors.idempotency_mismatch'));
});

it('fires the same domain events for app and website bookings', function () {
    Event::fake([BookingCreated::class, BookingConfirmed::class, BookingRescheduled::class, BookingCancelled::class]);

    $ref = $this->postJson(apiUrl('bookings'), bookingBody($this->salon), $this->h)->assertCreated()->json('data.reference');
    Event::assertDispatched(BookingCreated::class, fn ($e) => $e->booking->reference === $ref && $e->booking->source === 'app');
    Event::assertDispatched(BookingConfirmed::class, fn ($e) => $e->booking->reference === $ref);

    $this->actingAs($this->client, 'client')->postJson(tenantUrl($this->salon->tenant, 'book/api/checkout'), [
        'branch' => $this->salon->branch->id, 'items' => [['service_id' => $this->salon->services['cut']->id]], 'starts_at' => '2026-12-01T10:00:00Z',
    ])->assertOk();
    Event::assertDispatched(BookingCreated::class, fn ($e) => $e->booking->source === 'website');
    Event::assertDispatchedTimes(BookingCreated::class, 2);
    Event::assertDispatchedTimes(BookingConfirmed::class, 2);

    $this->postJson(apiUrl("bookings/{$ref}/cancel"), [], $this->h)->assertOk();
    Event::assertDispatched(BookingCancelled::class, fn ($e) => $e->booking->reference === $ref);
});

it('scopes bookings to the signed-in client and the issuing salon', function () {
    $other = bookingClient($this->salon->tenant, '+97455550002', 'Noor');
    $foreign = asTenant($this->salon->tenant, fn () => app(CreateBooking::class)->handle([
        'branch' => $this->salon->branch, 'items' => [['service_id' => $this->salon->services['cut']->id]], 'starts_at' => atLocal($this->salon, '2026-12-03 12:00'), 'client' => $other,
    ]));

    $this->getJson(apiUrl('bookings'), $this->h)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(apiUrl("bookings/{$foreign->reference}"), $this->h)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->postJson(apiUrl("bookings/{$foreign->reference}/cancel"), [], $this->h)->assertNotFound();
    expect($foreign->fresh()->status)->toBe(BookingStatus::Confirmed);

    // A token issued by salon A is useless at salon B.
    $b = bookingSalon(['staff' => ['Mona']]);
    $this->app['auth']->forgetGuards();
    $this->getJson(apiUrl('bookings'), apiHeaders($b->tenant, clientToken($this->client)))->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
});

it('gates the booking API behind the booking module', function () {
    withoutModule($this->salon->tenant, 'booking');
    $q = http_build_query(['branch_id' => $this->salon->branch->id, 'services' => [$this->salon->services['cut']->id], 'date' => '2026-12-01']);

    $this->getJson(apiUrl('availability?'.$q), apiHeaders($this->salon->tenant))
        ->assertForbidden()->assertJsonPath('module', 'booking')->assertJsonPath('error.code', 'module_disabled');
    $this->postJson(apiUrl('bookings'), bookingBody($this->salon), $this->h)->assertForbidden()->assertJsonPath('module', 'booking');
});

it('requires a client app token', function () {
    $this->postJson(apiUrl('bookings'), bookingBody($this->salon), apiHeaders($this->salon->tenant))->assertUnauthorized();
    $staff = tenantUser($this->salon->tenant, 'receptionist');
    $this->getJson(apiUrl('bookings'), apiHeaders($this->salon->tenant, teamToken($staff)))->assertForbidden()->assertJsonPath('message', __('api.errors.wrong_app'));
});
