<?php

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\CompleteBooking;
use App\Domain\Booking\Actions\CreateBooking;
use App\Domain\Booking\Actions\JoinWaitlist;
use App\Domain\Booking\Actions\OfferWaitlistSlot;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WaitlistEntry;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-28 09:00', 'Asia/Qatar'));
    MessagingManager::fake();
    Model::preventLazyLoading(); // mirror local dev: catch N+1 / lazy loads
});

afterEach(fn () => Model::preventLazyLoading(false));

function bookAt(object $salon, string $local, $client, string $service = 'cut', ?int $staffId = null): Booking
{
    return asTenant($salon->tenant, fn () => app(CreateBooking::class)->handle([
        'branch' => $salon->branch, 'items' => [['service_id' => $salon->services[$service]->id, 'staff_id' => $staffId]],
        'starts_at' => atLocal($salon, $local), 'client' => $client,
    ]));
}

it('sends 24h and 2h reminders once, with times in the branch timezone', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $client = bookingClient($salon->tenant);
    $booking = bookAt($salon, '2026-12-01 18:30', $client); // 15:30 UTC
    FakeChannel::reset();

    $this->travelTo(atLocal($salon, '2026-11-30 19:00')); // 23.5h before
    $this->artisan('booking:reminders')->assertSuccessful();
    $sent = FakeChannel::sentTo('whatsapp', $client->phone);
    expect($sent)->toHaveCount(1)->and($sent[0]['message']->body)->toContain('18:30')->not->toContain('15:30')
        ->and($sent[0]['message']->body)->toContain(__('booking.notify.running_late'))
        ->and($sent[0]['message']->template)->toBe('booking_reminder_24h')
        ->and($booking->fresh()->reminder_24h_sent_at)->not->toBeNull();

    $this->artisan('booking:reminders'); // idempotent
    expect(FakeChannel::sentTo('whatsapp', $client->phone))->toHaveCount(1);

    $this->travelTo(atLocal($salon, '2026-12-01 16:45'));
    $this->artisan('booking:reminders');
    $this->artisan('booking:reminders');
    $sent = FakeChannel::sentTo('whatsapp', $client->phone);
    expect($sent)->toHaveCount(2)->and($sent[1]['message']->template)->toBe('booking_reminder_2h')->and($booking->fresh()->reminder_2h_sent_at)->not->toBeNull();
    expect(FakeChannel::sentTo('email', $client->email))->toHaveCount(2);
});

it('messages the client in their language and falls back to SMS', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $client = bookingClient($salon->tenant);
    asTenant($salon->tenant, fn () => $client->update(['locale' => 'ar']));
    FakeChannel::$failFor['whatsapp'] = true;

    $booking = bookAt($salon, '2026-12-01 11:00', $client);
    $sms = FakeChannel::sentTo('sms', $client->phone);
    expect($sms)->toHaveCount(1)->and($sms[0]['message']->body)->toContain('تم تأكيد موعدك')->toContain('11:00')->toContain($booking->reference)
        ->and($sms[0]['message']->locale)->toBe('ar');
});

it('sends the post-visit thank-you with the review link and a rebook suggestion', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    asTenant($salon->tenant, fn () => $salon->services['cut']->update(['rebook_after_days' => 42]));
    $client = bookingClient($salon->tenant);
    $booking = bookAt($salon, '2026-11-28 11:00', $client);
    $this->travelTo(atLocal($salon, '2026-11-28 12:00'));
    asTenant($salon->tenant, fn () => app(CompleteBooking::class)->handle($booking));
    FakeChannel::reset();

    $this->travelTo(atLocal($salon, '2026-11-28 15:00'));
    $this->artisan('booking:reminders');
    $this->artisan('booking:reminders');

    $sent = FakeChannel::sentTo('whatsapp', $client->phone);
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['message']->buttons[0]['url'])->toContain('/review/'.$booking->reference.'?signature=')
        ->and($sent[0]['message']->body)->toContain('/book?branch=main&service=cut')
        ->and($booking->fresh()->review_requested_at)->not->toBeNull();
});

it('auto-offers a freed slot to the first matching waitlist entry with a 30-minute claim link', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $holder = bookingClient($salon->tenant, '+97455550001', 'Holder');
    $first = bookingClient($salon->tenant, '+97455550002', 'First');
    $second = bookingClient($salon->tenant, '+97455550003', 'Second');

    // Layla is fully booked on 1 Dec.
    $busy = asTenant($salon->tenant, function () use ($salon, $holder) {
        $b = Booking::create(['branch_id' => $salon->branch->id, 'client_id' => $holder->id, 'status' => 'confirmed', 'starts_at' => atLocal($salon, '2026-12-01 10:00'), 'ends_at' => atLocal($salon, '2026-12-01 22:00')]);
        $b->items()->create(['service_id' => $salon->services['cut']->id, 'staff_id' => $salon->staff[0]->id, 'starts_at' => $b->starts_at, 'ends_at' => $b->ends_at, 'price' => 1, 'duration_minutes' => 720]);

        return $b;
    });
    expect(slotTimes($salon, ['cut'], '2026-12-01'))->toBe([]);

    asTenant($salon->tenant, function () use ($salon, $first, $second) {
        app(JoinWaitlist::class)->handle($first, $salon->branch, [$salon->services['cut']->id], '2026-12-01');
        $this->travel(1)->seconds();
        app(JoinWaitlist::class)->handle($second, $salon->branch, [$salon->services['cut']->id], '2026-12-01');
    });
    FakeChannel::reset();

    asTenant($salon->tenant, fn () => app(CancelBooking::class)->handle($busy, 'salon', 'Freed'));

    $entry = asTenant($salon->tenant, fn () => WaitlistEntry::where('client_id', $first->id)->first());
    expect($entry->status)->toBe('offered')
        ->and($entry->offer_expires_at->diffInMinutes(now()))->toEqual(-30.0)
        ->and(asTenant($salon->tenant, fn () => WaitlistEntry::where('client_id', $second->id)->value('status')))->toBe('waiting');
    $offer = FakeChannel::sentTo('whatsapp', $first->phone);
    expect($offer)->toHaveCount(1)->and($offer[0]['message']->template)->toBe('booking_waitlist_offer');
    $claimUrl = $offer[0]['message']->buttons[0]['url'];

    // The offered slot is held for the first client: nobody else can take it meanwhile.
    expect(slotTimes($salon, ['cut'], '2026-12-01', ['cache' => false]))->not->toContain('10:00');

    $this->get($claimUrl)->assertOk()->assertSee(__('booking.waitlist.claim'));
    $this->post($claimUrl)->assertRedirect();
    $claimed = asTenant($salon->tenant, fn () => Booking::where('client_id', $first->id)->first());
    expect($claimed->status)->toBe(BookingStatus::Confirmed)
        ->and($claimed->starts_at->toIso8601ZuluString())->toBe(atLocal($salon, '2026-12-01 10:00')->toIso8601ZuluString())
        ->and($entry->fresh()->status)->toBe('booked');

    // Expired links are refused.
    $this->travel(31)->minutes();
    $this->get($claimUrl)->assertForbidden();
});

it('passes an expired waitlist offer to the next person in line', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    [$a, $b] = [bookingClient($salon->tenant, '+97455550002', 'A'), bookingClient($salon->tenant, '+97455550003', 'B')];
    asTenant($salon->tenant, function () use ($salon, $a, $b) {
        app(JoinWaitlist::class)->handle($a, $salon->branch, [$salon->services['cut']->id], '2026-12-01');
        $this->travel(1)->seconds();
        app(JoinWaitlist::class)->handle($b, $salon->branch, [$salon->services['cut']->id], '2026-12-01');
        app(OfferWaitlistSlot::class)->handle($salon->branch, '2026-12-01');
    });
    expect(asTenant($salon->tenant, fn () => WaitlistEntry::where('client_id', $a->id)->value('status')))->toBe('offered');

    $this->travel(31)->minutes();
    $this->artisan('booking:housekeeping')->assertSuccessful();

    expect(asTenant($salon->tenant, fn () => WaitlistEntry::where('client_id', $a->id)->value('status')))->toBe('expired')
        ->and(asTenant($salon->tenant, fn () => WaitlistEntry::where('client_id', $b->id)->value('status')))->toBe('offered')
        ->and(FakeChannel::sentTo('whatsapp', $b->phone))->toHaveCount(1);
});

it('expires unpaid online bookings after the payment window', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    onlineGateway($salon->tenant);
    $booking = asTenant($salon->tenant, fn () => app(CreateBooking::class)->handle([
        'branch' => $salon->branch, 'items' => [['service_id' => $salon->services['cut']->id]], 'starts_at' => atLocal($salon, '2026-12-01 11:00'),
        'client' => bookingClient($salon->tenant), 'payment_preference' => 'full',
    ]));
    expect($booking->status)->toBe(BookingStatus::PendingPayment);

    $this->artisan('booking:housekeeping');
    expect($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);

    $this->travel(31)->minutes();
    $this->artisan('booking:housekeeping');
    expect($booking->fresh()->status)->toBe(BookingStatus::CancelledBySalon);
});
