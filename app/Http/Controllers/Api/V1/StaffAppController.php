<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Actions\RequestTimeOff;
use App\Domain\Booking\Actions\CheckInBooking;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\BookingItem;
use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Models\ClientNote;
use App\Domain\POS\Actions\BuildCommissionReport;
use App\Domain\POS\Models\Commission;
use App\Domain\Team\Models\Staff;
use App\Domain\Team\Models\TimeOff;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TeamMemberResource;
use App\Models\User;
use App\Support\Exceptions\DomainException;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * @group Staff app
 *
 * The signed-in stylist's day, check-in, commissions, time off and client cards. Branch restrictions of the
 * team member apply (same as the dashboard); bookings are shown in each branch's timezone.
 */
class StaffAppController extends Controller
{
    /** Me (profile, permissions, today's appointment count). */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => (new TeamMemberResource($this->user($request)))->resolve($request), 'meta' => ['appointments_today' => $this->todayCount($request)]]);
    }

    /**
     * My calendar for a day.
     *
     * One entry per booking with my services of that day (branch-local date). Client notes / allergies need
     * clients.view; first-visit flag from the client's visit count.
     *
     * @queryParam date string Branch-local date (default: today). Example: 2026-12-01
     */
    public function calendar(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $staff = $this->staff($request);
        $user = $this->user($request);
        $date = $request->query('date') ?: now(tenant()->timezone)->toDateString();
        $center = CarbonImmutable::parse($date, 'UTC');

        $items = BookingItem::query()->where('staff_id', $staff->id)
            ->whereBetween('starts_at', [$center->subHours(14), $center->addDay()->addHours(14)])
            ->whereHas('booking', fn ($q) => $q->whereNotIn('status', [BookingStatus::CancelledByClient->value, BookingStatus::CancelledBySalon->value]))
            ->with(['booking.client', 'booking.branch', 'service'])
            ->orderBy('starts_at')->get()
            ->filter(fn (BookingItem $i) => $i->booking && $i->starts_at->copy()->setTimezone($i->booking->branch?->timezone ?? tenant()->timezone)->toDateString() === $date);

        $canSeeClient = $user->can('clients.view');
        $entries = $items->groupBy('booking_id')->map(function (Collection $mine) use ($canSeeClient) {
            /** @var Booking $booking */
            $booking = $mine->first()->booking;
            $client = $booking->client;

            return [
                'booking_id' => $booking->id,
                'reference' => $booking->reference,
                'status' => $booking->status->value,
                'status_label' => $booking->status->getLabel(),
                'starts_at' => $mine->min('starts_at')->copy()->utc()->toIso8601ZuluString(),
                'ends_at' => $mine->max('ends_at')->copy()->utc()->toIso8601ZuluString(),
                'services' => $mine->map(fn (BookingItem $i) => (string) $i->service?->name)->values(),
                'branch_id' => $booking->branch_id,
                'branch_name' => $booking->branch?->name,
                'timezone' => $booking->branch?->timezone,
                'notes' => $booking->client_notes,
                'can_check_in' => in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::PendingPayment], true),
                'client' => $client ? [
                    'id' => $client->id,
                    'name' => $client->name,
                    'phone' => $canSeeClient ? $client->phone : null,
                    'notes' => $canSeeClient ? $client->notes : null,
                    'allergies' => $canSeeClient ? $client->allergies : null,
                    'first_visit' => (int) $client->visits_count === 0,
                    'is_vip' => (bool) $client->is_vip,
                ] : ['id' => null, 'name' => $mine->first()->guest_name ?? __('account.guest'), 'first_visit' => false],
            ];
        })->values();

        return response()->json(['data' => $entries, 'meta' => ['date' => $date, 'staff_id' => $staff->id]]);
    }

    /**
     * Check a client in.
     *
     * Allowed for my own appointments, or for anyone with bookings.manage (reception).
     */
    public function checkIn(Request $request, string $reference, CheckInBooking $checkIn): JsonResponse
    {
        $user = $this->user($request);
        $booking = Booking::query()->where('reference', strtoupper($reference))->with('items')->firstOrFail();
        $staffId = $user->staffProfile?->id;
        $own = $staffId && $booking->items->contains('staff_id', $staffId);
        if (! $own && ! $user->can('bookings.manage')) {
            return response()->json(['message' => __('api.staff.not_your_booking')], 403);
        }

        $booking = $checkIn->handle($booking, __('api.staff.checked_in_from_app', ['name' => $user->name]));

        return response()->json(['data' => ['reference' => $booking->reference, 'status' => $booking->status->value, 'checked_in_at' => $booking->checked_in_at?->toIso8601ZuluString()]]);
    }

    /**
     * My commissions and tips.
     *
     * Same numbers as the dashboard payroll report (BuildCommissionReport); refunds reverse in the period they happen.
     *
     * @queryParam from string Default: first day of this month. Example: 2026-11-01
     * @queryParam to string Default: today. Example: 2026-11-30
     */
    public function commissions(Request $request, BuildCommissionReport $report): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $staff = $this->staff($request);
        $tz = tenant()->timezone;
        $from = CarbonImmutable::parse($request->query('from') ?: now($tz)->startOfMonth()->toDateString(), $tz);
        $to = CarbonImmutable::parse($request->query('to') ?: now($tz)->toDateString(), $tz);
        $currency = tenant()->currency ?? 'QAR';

        $row = collect($report->handle($from, $to))->firstWhere('staff_id', $staff->id)
            ?? ['lines' => 0, 'services' => 0, 'products' => 0, 'revenue' => 0, 'commission' => 0, 'tips' => 0, 'payout' => 0];
        $commissions = Commission::query()->where('staff_id', $staff->id)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->with('saleItem:id,type,name')->latest('id')->limit(200)->get();
        $byType = fn (string $type) => (int) $commissions->filter(fn ($c) => $c->saleItem?->type === $type)->sum('amount');

        return response()->json(['data' => [
            'period' => $from->translatedFormat('j M').' – '.$to->translatedFormat('j M Y'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => Money::of((int) $row['payout'], $currency),
            'commission' => Money::of((int) $row['commission'], $currency),
            'services_total' => Money::of($byType('service'), $currency),
            'products_total' => Money::of($byType('product'), $currency),
            'tips' => Money::of((int) $row['tips'], $currency),
            'revenue' => Money::of((int) $row['revenue'], $currency),
            'lines' => $commissions->map(fn (Commission $c) => [
                'label' => (string) ($c->saleItem?->name ?? __('api.staff.commission')),
                'type' => $c->saleItem?->type,
                'base' => Money::of((int) $c->base_amount, $currency),
                'amount' => Money::of((int) $c->amount, $currency),
                'reversal' => $c->reversal_of_id !== null,
                'date' => $c->created_at->toIso8601ZuluString(),
            ])->values(),
        ]]);
    }

    /** My time-off requests (latest 50). */
    public function timeOffIndex(Request $request): JsonResponse
    {
        $rows = TimeOff::query()->where('staff_id', $this->staff($request)->id)->latest('starts_at')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn (TimeOff $t) => $this->timeOffPayload($t))->values()]);
    }

    /**
     * Request time off.
     *
     * Creates a pending request the owner approves in the dashboard.
     *
     * @bodyParam from string required First day (salon timezone). Example: 2026-12-10
     * @bodyParam to string required Last day. Example: 2026-12-12
     * @bodyParam reason string Example: Family trip
     * @bodyParam type string leave|sick|training. Example: leave
     */
    public function requestTimeOff(Request $request, RequestTimeOff $action): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'reason' => ['nullable', 'string', 'max:250'],
            'type' => ['nullable', 'in:leave,sick,training'],
        ]);
        $timeOff = $action->handle($this->staff($request), $data['from'], $data['to'], $data['reason'] ?? null, $data['type'] ?? 'leave');

        return response()->json(['data' => $this->timeOffPayload($timeOff), 'message' => __('api.staff.time_off_requested')], 201);
    }

    /**
     * Client card.
     *
     * Needs clients.view. Stylists limited to their own appointments (bookings.own_only) only see their clients.
     * Private notes need clients.private_notes.
     */
    public function client(Request $request, int $id): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->can('clients.view'), 403);
        $client = Client::query()->findOrFail($id);
        $staffId = $user->staffProfile?->id;
        if (! $user->can('bookings.manage') && $user->can('bookings.own_only')) {
            $served = $staffId && BookingItem::query()->where('staff_id', $staffId)->whereHas('booking', fn ($q) => $q->where('client_id', $client->id))->exists();
            abort_unless($served, 403);
        }

        $canPrivate = $user->can('clients.private_notes');
        $notes = ClientNote::query()->where('client_id', $client->id)->when(! $canPrivate, fn ($q) => $q->where('is_private', false))
            ->with('user:id,name')->latest()->limit(20)->get();
        $bookings = Booking::query()->where('client_id', $client->id)->with(['items.service', 'items.staff'])->latest('starts_at')->limit(10)->get();
        $currency = tenant()->currency ?? 'QAR';

        return response()->json(['data' => [
            'id' => $client->id,
            'name' => $client->name,
            'phone' => $client->phone,
            'email' => $client->email,
            'gender' => $client->gender,
            'birthday' => $client->birthday?->toDateString(),
            'is_vip' => (bool) $client->is_vip,
            'visits_count' => (int) $client->visits_count,
            'no_shows_count' => (int) $client->no_shows_count,
            'last_visit_at' => $client->last_visit_at?->toIso8601ZuluString(),
            'lifetime_value' => $user->can('reports.revenue') ? Money::of((int) $client->lifetime_value, $currency) : null,
            'loyalty' => ['points' => (int) $client->loyalty_points, 'tier' => $client->loyalty_tier],
            'notes' => $client->notes,
            'allergies' => $client->allergies,
            'private_notes_visible' => $canPrivate,
            'timeline' => $notes->map(fn (ClientNote $n) => ['id' => $n->id, 'body' => $n->body, 'private' => (bool) $n->is_private, 'by' => $n->user?->name, 'created_at' => $n->created_at?->toIso8601ZuluString()])->values(),
            'bookings' => $bookings->map(fn (Booking $b) => [
                'reference' => $b->reference, 'status' => $b->status->value, 'starts_at' => $b->starts_at->copy()->utc()->toIso8601ZuluString(),
                'services' => $b->items->map(fn ($i) => (string) $i->service?->name)->values(), 'staff' => $b->items->map(fn ($i) => $i->staff?->name)->filter()->unique()->values(),
            ])->values(),
        ]]);
    }

    // ------------------------------------------------------------------------------------------

    protected function user(Request $request): User
    {
        return $request->user();
    }

    /** Bookings with my services today (salon timezone); null without a stylist profile. */
    protected function todayCount(Request $request): ?int
    {
        $staff = $this->user($request)->staffProfile;
        if (! $staff) {
            return null;
        }
        $tz = tenant()->timezone;

        return BookingItem::query()->where('staff_id', $staff->id)
            ->whereBetween('starts_at', [now($tz)->startOfDay()->utc(), now($tz)->endOfDay()->utc()])
            ->whereHas('booking', fn ($q) => $q->blocking())->distinct()->count('booking_id');
    }

    protected function staff(Request $request): Staff
    {
        return $this->user($request)->staffProfile ?? throw new DomainException(__('api.staff.no_profile'));
    }

    protected function timeOffPayload(TimeOff $t): array
    {
        $tz = tenant()->timezone;

        return [
            'id' => $t->id, 'type' => $t->type, 'status' => $t->status, 'reason' => $t->reason,
            'from' => $t->starts_at->copy()->setTimezone($tz)->toDateString(), 'to' => $t->ends_at->copy()->setTimezone($tz)->toDateString(),
            'starts_at' => $t->starts_at->copy()->utc()->toIso8601ZuluString(), 'ends_at' => $t->ends_at->copy()->utc()->toIso8601ZuluString(),
        ];
    }
}
