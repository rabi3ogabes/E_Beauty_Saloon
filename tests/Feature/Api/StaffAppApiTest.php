<?php

use App\Domain\Booking\Actions\CreateBooking;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\CRM\Models\ClientNote;
use App\Domain\Messaging\MessagingManager;
use App\Domain\POS\Actions\CompleteSale;
use App\Domain\Team\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

require_once __DIR__.'/../Booking/Helpers.php';
require_once __DIR__.'/../Pos/PosTestHelpers.php';
require_once __DIR__.'/ApiHelpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 09:00', 'Asia/Qatar'));
    MessagingManager::fake();
    $this->salon = bookingSalon();
    [$this->layla, $this->sofia] = $this->salon->staff;
    $this->stylist = tenantUser($this->salon->tenant, 'stylist', [$this->salon->branch->id]);
    asTenant($this->salon->tenant, fn () => $this->layla->update(['user_id' => $this->stylist->id]));
    $this->client = bookingClient($this->salon->tenant);
});

function staffLogin($test, string $email, string $password = 'password-1234', array $extra = [], string $app = 'staff')
{
    return $test->postJson(apiUrl("{$app}/auth/login"), array_merge(['email' => $email, 'password' => $password, 'device_name' => 'Test phone'], $extra), apiHeaders($test->salon->tenant));
}

function bookStaff(object $salon, $client, $staff, string $local = '2026-12-01 11:00')
{
    return asTenant($salon->tenant, fn () => app(CreateBooking::class)->handle([
        'branch' => $salon->branch, 'items' => [['service_id' => $salon->services['cut']->id, 'staff_id' => $staff->id]],
        'starts_at' => atLocal($salon, $local), 'client' => $client, 'source' => 'dashboard',
    ]));
}

it('signs a stylist in with e-mail + password and issues a staff-only token', function () {
    $res = staffLogin($this, $this->stylist->email)->assertOk()
        ->assertJsonPath('abilities', ['staff'])->assertJsonPath('user.staff.id', $this->layla->id)
        ->assertJsonPath('user.is_owner', false)->assertJsonPath('user.branch_ids', [$this->salon->branch->id]);
    expect($res->json('user.permissions'))->toContain('bookings.own_only')->not->toContain('bookings.manage');
    $token = $res->json('token');

    $this->getJson(apiUrl('staff/me'), apiHeaders($this->salon->tenant, $token))->assertOk()->assertJsonPath('data.staff.name', 'Layla');
    $this->getJson(apiUrl('owner/kpis'), apiHeaders($this->salon->tenant, $token))->assertForbidden()->assertJsonPath('message', __('api.errors.wrong_app'));
    $this->getJson(apiUrl('me'), apiHeaders($this->salon->tenant, $token))->assertForbidden();

    $this->postJson(apiUrl('staff/auth/logout'), [], apiHeaders($this->salon->tenant, $token))->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->getJson(apiUrl('staff/me'), apiHeaders($this->salon->tenant, $token))->assertUnauthorized();
});

it('rejects bad credentials, other salons, the wrong app and throttles attempts', function () {
    staffLogin($this, $this->stylist->email, 'nope')->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');
    staffLogin($this, $this->stylist->email, app: 'owner')->assertForbidden()->assertJsonPath('error.code', 'app_not_allowed');

    $other = bookingSalon(['staff' => ['Mona']]);
    $this->postJson(apiUrl('staff/auth/login'), ['email' => $this->stylist->email, 'password' => 'password-1234', 'device_name' => 'x'], apiHeaders($other->tenant))
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');

    // A valid staff token of salon A is rejected by salon B.
    $token = teamToken($this->stylist);
    $this->getJson(apiUrl('staff/me'), apiHeaders($other->tenant, $token))->assertUnauthorized();

    RateLimiter::clear('team-login:'.$this->salon->tenant->id.':'.$this->stylist->email);
    foreach (range(1, 5) as $i) {
        staffLogin($this, $this->stylist->email, 'wrong-'.$i);
    }
    staffLogin($this, $this->stylist->email)->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
});

it('requires the authenticator code when app MFA is enabled', function () {
    $g = app(Google2FA::class);
    $secret = $g->generateSecretKey();
    $this->stylist->saveAppAuthenticationSecret($secret);
    $this->stylist->saveAppAuthenticationRecoveryCodes([bcrypt('recover-me-1')]);

    staffLogin($this, $this->stylist->email)->assertStatus(422)->assertJsonPath('error.code', 'mfa_required')->assertJsonPath('mfa_required', true);
    staffLogin($this, $this->stylist->email, extra: ['code' => '000000'])->assertStatus(422)->assertJsonPath('error.code', 'mfa_invalid');
    staffLogin($this, $this->stylist->email, extra: ['code' => $g->getCurrentOtp($secret)])->assertOk()->assertJsonPath('user.mfa_enabled', true);

    staffLogin($this, $this->stylist->email, extra: ['code' => 'recover-me-1'])->assertOk();
    staffLogin($this, $this->stylist->email, extra: ['code' => 'recover-me-1'])->assertStatus(422); // consumed
});

it('shows my day in the branch timezone with client details', function () {
    $mine = bookStaff($this->salon, $this->client, $this->layla);
    bookStaff($this->salon, bookingClient($this->salon->tenant, '+97455550077', 'Hind'), $this->sofia);
    bookStaff($this->salon, $this->client, $this->layla, '2026-12-02 11:00');
    asTenant($this->salon->tenant, fn () => $this->client->update(['notes' => 'Prefers quiet', 'allergies' => 'Latex']));

    $this->getJson(apiUrl('staff/me/calendar?date=2026-12-01'), apiHeaders($this->salon->tenant, teamToken($this->stylist)))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $mine->reference)
        ->assertJsonPath('data.0.starts_at', '2026-12-01T08:00:00Z')
        ->assertJsonPath('data.0.services', ['Cut'])
        ->assertJsonPath('data.0.client.name', 'Aisha')
        ->assertJsonPath('data.0.client.notes', 'Prefers quiet')
        ->assertJsonPath('data.0.client.allergies', 'Latex')
        ->assertJsonPath('data.0.client.first_visit', true)
        ->assertJsonPath('data.0.can_check_in', true);
});

it('checks in my own appointment, not a colleague\'s, unless the member can manage bookings', function () {
    $mine = bookStaff($this->salon, $this->client, $this->layla);
    $sofias = bookStaff($this->salon, bookingClient($this->salon->tenant, '+97455550077', 'Hind'), $this->sofia, '2026-12-01 12:00');
    $h = apiHeaders($this->salon->tenant, teamToken($this->stylist));

    $this->postJson(apiUrl("staff/bookings/{$mine->reference}/check-in"), [], $h)->assertOk()->assertJsonPath('data.status', 'checked_in');
    $this->postJson(apiUrl("staff/bookings/{$sofias->reference}/check-in"), [], $h)->assertForbidden()->assertJsonPath('message', __('api.staff.not_your_booking'));
    expect($sofias->fresh()->status)->toBe(BookingStatus::Confirmed);

    $reception = tenantUser($this->salon->tenant, 'receptionist', [$this->salon->branch->id]);
    $this->app['auth']->forgetGuards();
    $this->postJson(apiUrl("staff/bookings/{$sofias->reference}/check-in"), [], apiHeaders($this->salon->tenant, teamToken($reception)))->assertOk()->assertJsonPath('data.status', 'checked_in');
});

it('requests time off as a pending request and refuses overlaps', function () {
    $h = apiHeaders($this->salon->tenant, teamToken($this->stylist));
    $this->postJson(apiUrl('staff/me/time-off'), ['from' => '2026-12-10', 'to' => '2026-12-12', 'reason' => 'Family trip'], $h)
        ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.from', '2026-12-10')->assertJsonPath('data.to', '2026-12-12');
    $this->postJson(apiUrl('staff/me/time-off'), ['from' => '2026-12-12', 'to' => '2026-12-13'], $h)->assertStatus(422)->assertJsonPath('message', __('api.staff.time_off_overlaps'));
    $this->postJson(apiUrl('staff/me/time-off'), ['from' => '2026-11-01', 'to' => '2026-11-02'], $h)->assertStatus(422)->assertJsonValidationErrors('from');
    $this->getJson(apiUrl('staff/me/time-off'), $h)->assertOk()->assertJsonCount(1, 'data');

    $row = asTenant($this->salon->tenant, fn () => TimeOff::firstOrFail());
    expect($row->staff_id)->toBe($this->layla->id)->and($row->starts_at->toIso8601ZuluString())->toBe('2026-12-09T21:00:00Z');
});

it('shows client cards with private notes only to members allowed to see them', function () {
    bookStaff($this->salon, $this->client, $this->layla);
    $stranger = bookingClient($this->salon->tenant, '+97455550078', 'Stranger');
    asTenant($this->salon->tenant, function () {
        ClientNote::create(['client_id' => $this->client->id, 'body' => 'Private: sensitive scalp', 'is_private' => true]);
        ClientNote::create(['client_id' => $this->client->id, 'body' => 'Loves gloss', 'is_private' => false]);
    });

    $this->getJson(apiUrl("staff/clients/{$this->client->id}"), apiHeaders($this->salon->tenant, teamToken($this->stylist)))->assertOk()
        ->assertJsonPath('data.private_notes_visible', true)->assertJsonCount(2, 'data.timeline')->assertJsonPath('data.lifetime_value', null);
    $this->getJson(apiUrl("staff/clients/{$stranger->id}"), apiHeaders($this->salon->tenant, teamToken($this->stylist)))->assertForbidden();

    $reception = tenantUser($this->salon->tenant, 'receptionist', [$this->salon->branch->id]);
    $this->app['auth']->forgetGuards();
    $this->getJson(apiUrl("staff/clients/{$this->client->id}"), apiHeaders($this->salon->tenant, teamToken($reception)))->assertOk()
        ->assertJsonPath('data.private_notes_visible', false)->assertJsonCount(1, 'data.timeline')->assertJsonPath('data.timeline.0.body', 'Loves gloss');
});

it('reports my commissions and tips from POS sales', function () {
    $s = posSalon(['tax_percent' => 0]);
    $user = tenantUser($s->tenant, 'stylist', [$s->branch->id]);
    asTenant($s->tenant, function () use ($s, $user) {
        $s->layla->update(['user_id' => $user->id]);
        app(CompleteSale::class)->handle([
            'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id,
            'lines' => [['type' => 'service', 'id' => $s->services[0]->id, 'staff_id' => $s->layla->id]], // 150 × 20% = 30
            'tips' => [$s->layla->id => 1000],
            'payments' => [['method' => 'cash', 'tendered' => 16000]],
        ], $s->owner);
    });

    $this->getJson(apiUrl('staff/me/commissions'), apiHeaders($s->tenant, teamToken($user)))->assertOk()
        ->assertJsonPath('data.commission.amount', 3000)->assertJsonPath('data.services_total.amount', 3000)
        ->assertJsonPath('data.tips.amount', 1000)->assertJsonPath('data.total.amount', 4000)
        ->assertJsonPath('data.lines.0.label', 'Blow-dry')->assertJsonPath('data.lines.0.amount.amount', 3000);
});

it('refuses staff endpoints for members without a stylist profile or app access', function () {
    $accountant = tenantUser($this->salon->tenant, 'accountant');
    staffLogin($this, $accountant->email)->assertForbidden()->assertJsonPath('error.code', 'app_not_allowed');

    $reception = tenantUser($this->salon->tenant, 'receptionist', [$this->salon->branch->id]);
    $this->getJson(apiUrl('staff/me/calendar'), apiHeaders($this->salon->tenant, teamToken($reception)))->assertStatus(422)->assertJsonPath('message', __('api.staff.no_profile'));

    $dashboardToken = $this->stylist->createToken('dashboard')->plainTextToken; // wildcard token is not an app token
    $this->app['auth']->forgetGuards();
    $this->getJson(apiUrl('staff/me'), apiHeaders($this->salon->tenant, $dashboardToken))->assertForbidden();
});
