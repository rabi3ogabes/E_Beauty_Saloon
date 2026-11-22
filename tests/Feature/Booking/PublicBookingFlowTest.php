<?php

use App\Domain\Booking\Actions\CreateBooking;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SlotHold;
use App\Domain\Booking\Support\BookingLinks;
use App\Domain\Branches\Models\BranchHoliday;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Payments\Gateways\GatewayManager;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 09:00', 'Asia/Qatar'));
    MessagingManager::fake();
    GatewayManager::fake();
});

function flowItems(object $salon, string $service = 'cut', ?int $staff = null): array
{
    return [['service_id' => $salon->services[$service]->id, 'staff_id' => $staff]];
}

it('renders the booking wizard and resolves the deep-link contract', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $staffId = $salon->staff[0]->id;

    $this->get(tenantUrl($salon->tenant, "book?branch=main&service=cut,color&staff={$staffId}&look=LOOK-42&utm_source=instagram&campaign=7"))
        ->assertOk()
        ->assertSee(__('booking.flow.eyebrow'))
        ->assertViewHas('state', fn ($state) => $state['attribution']['look_reference'] === 'LOOK-42' && count($state['serviceIds']) === 2)
        ->assertSessionHas('booking.attribution', ['look_reference' => 'LOOK-42', 'utm_source' => 'instagram', 'campaign_id' => 7]);

    $this->get(tenantUrl($salon->tenant, "book?branch=main&service=cut&staff={$staffId}"))
        ->assertViewHas('state', fn ($state) => $state['serviceIds'] === [$salon->services['cut']->id] && $state['staffId'] === $staffId
            && $state['branchId'] === $salon->branch->id && $state['catalog']['categories'][0]['services'][0]['slug'] === 'cut');
});

it('renders the wizard in Arabic, right-to-left', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);

    $this->get(tenantUrl($salon->tenant, 'book?lang=ar'))->assertOk()
        ->assertSee('dir="rtl"', false)->assertSee(__('booking.flow.eyebrow', [], 'ar'))->assertSee('احجزي لحظتك');
});

it('serves the catalog and availability as JSON in the branch timezone', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);

    $this->getJson(tenantUrl($salon->tenant, 'book/api/catalog?branch='.$salon->branch->id))
        ->assertOk()->assertJsonPath('categories.0.services.0.name', 'Cut')->assertJsonPath('staff.0.name', 'Layla')
        ->assertJsonCount(61, 'dates');

    $res = $this->getJson(tenantUrl($salon->tenant, 'book/api/availability?'.http_build_query(['branch' => $salon->branch->id, 'items' => json_encode(flowItems($salon)), 'date' => '2026-12-01'])))
        ->assertOk()->assertJsonPath('periods.morning.0.time', '10:00')->assertJsonPath('periods.morning.0.starts_at', '2026-12-01T07:00:00Z');
    expect($res->json('count'))->toBe(45);

    asTenant($salon->tenant, fn () => BranchHoliday::create(['branch_id' => $salon->branch->id, 'date' => '2026-12-02']));
    $this->getJson(tenantUrl($salon->tenant, 'book/api/availability?'.http_build_query(['branch' => $salon->branch->id, 'items' => json_encode(flowItems($salon)), 'date' => '2026-12-02'])))
        ->assertOk()->assertJsonPath('count', 0)->assertJsonPath('next_available_date', '2026-12-03');
});

it('books as a guest with inline phone OTP and keeps campaign attribution', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $this->get(tenantUrl($salon->tenant, 'book?branch=main&service=cut&look=LOOK-9&utm_source=tiktok&campaign=12'));

    $hold = $this->postJson(tenantUrl($salon->tenant, 'book/api/hold'), ['branch' => $salon->branch->id, 'items' => flowItems($salon), 'starts_at' => '2026-12-01T08:00:00Z'])
        ->assertOk()->assertJsonPath('slot.time', '11:00')->assertJsonPath('payment_options.0.key', 'pay_at_salon')->json();

    // Not verified yet → 401
    $this->postJson(tenantUrl($salon->tenant, 'book/api/checkout'), ['branch' => $salon->branch->id, 'items' => flowItems($salon), 'starts_at' => '2026-12-01T08:00:00Z'])->assertStatus(401);

    $this->postJson(tenantUrl($salon->tenant, 'book/api/otp/send'), ['phone' => '5555 7777'])->assertOk()->assertJsonPath('phone', '+97455557777');
    $this->postJson(tenantUrl($salon->tenant, 'book/api/otp/verify'), ['phone' => '+97455557777', 'code' => '123456', 'name' => 'Mariam', 'email' => 'mariam@example.com'])
        ->assertOk()->assertJsonPath('client.name', 'Mariam');

    $res = $this->postJson(tenantUrl($salon->tenant, 'book/api/checkout'), [
        'branch' => $salon->branch->id, 'items' => flowItems($salon), 'starts_at' => '2026-12-01T08:00:00Z', 'hold_token' => $hold['token'],
        'payment_preference' => 'pay_at_salon', 'notes' => 'Long hair',
    ])->assertOk()->assertJsonPath('payment', false);

    $booking = asTenant($salon->tenant, fn () => Booking::with('client')->firstOrFail());
    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->client->phone)->toBe('+97455557777')
        ->and($booking->look_reference)->toBe('LOOK-9')->and($booking->utm_source)->toBe('tiktok')->and($booking->campaign_id)->toBe(12)
        ->and($booking->client_notes)->toBe('Long hair')
        ->and(FakeChannel::sentTo('whatsapp', '+97455557777'))->toHaveCount(2) // OTP + confirmation
        ->and(FakeChannel::sentTo('email', 'mariam@example.com'))->toHaveCount(1);
    expect(FakeChannel::sentTo('whatsapp', '+97455557777')[1]['message']->body)->toContain('11:00')->toContain($booking->reference);

    // Confirmation page with calendar links
    $this->get($res->json('redirect'))->assertOk()->assertSee($booking->reference)->assertSee('calendar.google.com', false)->assertSee(__('booking.confirmation.manage'));
    $ics = asTenant($salon->tenant, fn () => BookingLinks::ics($booking));
    $this->get($ics)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->assertSee('DTSTART:20261201T080000Z', false);
});

it('keeps a single active hold per browser session', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $url = tenantUrl($salon->tenant, 'book/api/hold');
    $this->postJson($url, ['branch' => $salon->branch->id, 'items' => flowItems($salon), 'starts_at' => '2026-12-01T08:00:00Z'])->assertOk();
    $second = $this->postJson($url, ['branch' => $salon->branch->id, 'items' => flowItems($salon), 'starts_at' => '2026-12-01T10:00:00Z'])->assertOk()->json('token');

    $holds = asTenant($salon->tenant, fn () => SlotHold::active()->get());
    expect($holds)->toHaveCount(1)->and($holds[0]->token)->toStartWith($second)
        ->and(slotTimes($salon, ['cut'], '2026-12-01', ['cache' => false]))->toContain('11:00')->not->toContain('13:00');
});

it('pays the deposit on the hosted page and comes back confirmed', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    onlineGateway($salon->tenant);
    asTenant($salon->tenant, fn () => $salon->services['color']->update(['deposit_type' => 'percent', 'deposit_value' => 2500]));
    $client = bookingClient($salon->tenant);
    $this->actingAs($client, 'client');

    $hold = $this->postJson(tenantUrl($salon->tenant, 'book/api/hold'), ['branch' => $salon->branch->id, 'items' => flowItems($salon, 'color'), 'starts_at' => '2026-12-01T09:00:00Z'])
        ->assertOk()->assertJsonPath('deposit', 10000)->json();
    expect(collect($hold['payment_options'])->pluck('key')->all())->toBe(['deposit', 'full']);

    $res = $this->postJson(tenantUrl($salon->tenant, 'book/api/checkout'), [
        'branch' => $salon->branch->id, 'items' => flowItems($salon, 'color'), 'starts_at' => '2026-12-01T09:00:00Z', 'hold_token' => $hold['token'], 'payment_preference' => 'deposit',
    ])->assertOk()->assertJsonPath('payment', true);
    $booking = asTenant($salon->tenant, fn () => Booking::firstOrFail());
    expect($booking->status)->toBe(BookingStatus::PendingPayment)->and($res->json('redirect'))->toContain('/payments/fake/');

    // Hosted (fake) page → confirm → core return flow → our confirmation page.
    $reference = basename(parse_url($res->json('redirect'), PHP_URL_PATH));
    $this->post(tenantUrl($salon->tenant, 'payments/fake/'.$reference), ['outcome' => 'success'])->assertRedirect();
    $back = $this->get(tenantUrl($salon->tenant, 'payments/return/'.$reference));
    expect($back->headers->get('Location'))->toContain('/book/confirmation/'.$booking->reference);

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Confirmed)->and($booking->amount_paid)->toBe(10000);
    $this->get($back->headers->get('Location'))->assertOk()->assertSee(__('booking.paid_online'));
});

it('lets clients manage a booking from the signed link: reschedule then cancel', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $client = bookingClient($salon->tenant);
    $booking = asTenant($salon->tenant, fn () => app(CreateBooking::class)->handle([
        'branch' => $salon->branch, 'items' => flowItems($salon), 'starts_at' => atLocal($salon, '2026-12-03 12:00'), 'client' => $client,
    ]));
    $url = asTenant($salon->tenant, fn () => BookingLinks::manage($booking));

    $this->get(tenantUrl($salon->tenant, 'booking/'.$booking->reference))->assertNotFound(); // no signature, not signed in
    $this->get($url)->assertOk()->assertSee($booking->reference)->assertSee(__('booking.manage.reschedule'));

    $this->getJson(tenantUrl($salon->tenant, 'booking/'.$booking->reference.'/slots?date=2026-12-04'))->assertOk()->assertJsonPath('periods.morning.0.time', '10:00');
    $this->postJson(tenantUrl($salon->tenant, 'booking/'.$booking->reference.'/reschedule'), ['starts_at' => '2026-12-04T10:00:00Z'])->assertOk();
    expect($booking->fresh()->starts_at->toIso8601ZuluString())->toBe('2026-12-04T10:00:00Z')->and($booking->fresh()->reschedule_count)->toBe(1);

    $this->post(tenantUrl($salon->tenant, 'booking/'.$booking->reference.'/cancel'), ['reason' => 'Travel'])->assertRedirect();
    expect($booking->fresh()->status)->toBe(BookingStatus::CancelledByClient);
});

it('shows the client their bookings in My account', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $client = bookingClient($salon->tenant);
    asTenant($salon->tenant, fn () => app(CreateBooking::class)->handle([
        'branch' => $salon->branch, 'items' => flowItems($salon, 'color'), 'starts_at' => atLocal($salon, '2026-12-03 12:00'), 'client' => $client,
    ]));

    $this->actingAs($client, 'client')->get(tenantUrl($salon->tenant, 'account/bookings'))
        ->assertOk()->assertSee(__('booking.account.upcoming'))->assertSee('Color')->assertSee(__('booking.account.manage'));
    $this->get(tenantUrl($salon->tenant, 'account'))->assertSee(__('booking.account.title'));
});

it('module disabled → /book returns 403 and the menu hides it', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $sub = $salon->tenant->currentSubscription;
    $sub->update(['modules' => array_values(array_diff($sub->modules, ['booking']))]);
    Feature::flushCache();

    $this->get(tenantUrl($salon->tenant, 'book'))->assertForbidden();
    $this->getJson(tenantUrl($salon->tenant, 'book/api/catalog?branch='.$salon->branch->id))->assertForbidden();
    $this->get(tenantUrl($salon->tenant, '/'))->assertOk()->assertDontSee('href="'.tenantUrl($salon->tenant, 'book').'"', false);

    $client = bookingClient($salon->tenant);
    $this->actingAs($client, 'client')->get(tenantUrl($salon->tenant, 'account'))->assertDontSee(__('booking.account.title'));
});
