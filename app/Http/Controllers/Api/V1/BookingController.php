<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Support\AvailabilityPresenter;
use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\CreateBooking;
use App\Domain\Booking\Actions\HoldSlot;
use App\Domain\Booking\Actions\RescheduleBooking;
use App\Domain\Booking\Actions\StartBookingPayment;
use App\Domain\Booking\Availability\AvailabilityEngine;
use App\Domain\Booking\Availability\LineBuilder;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\BookingLines;
use App\Domain\Booking\Support\BookingLinks;
use App\Domain\Booking\Support\BookingPayments;
use App\Domain\Branches\Models\Branch;
use App\Domain\CRM\Models\Client;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BookingResource;
use App\Support\Exceptions\DomainException;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Booking
 *
 * Availability, booking, payment hand-off, cancel and reschedule for the client app. Every write goes through
 * the same Booking Actions as the salon website (HoldSlot → CreateBooking → StartBookingPayment), so the same
 * domain events fire and the same rules apply.
 */
class BookingController extends Controller
{
    /**
     * Available times for one day.
     *
     * Slots are grouped by part of day in the branch timezone; `starts_at` is UTC and must be posted back unchanged.
     * When the day is empty `next_available_date` points to the next date with a free slot.
     *
     * @unauthenticated
     *
     * @queryParam branch_id integer required Branch. Example: 1
     * @queryParam services integer[] required Service ids, booked back-to-back in this order. Example: [3]
     * @queryParam staff_id integer Preferred stylist for every service (omit = no preference). Example: 2
     * @queryParam date string required Branch-local date (Y-m-d). Example: 2026-12-01
     * @queryParam variants object Variant per service: variants[{service_id}]={variant_id}.
     * @queryParam addons object Add-ons per service: addons[{service_id}][]={addon_id}.
     */
    public function availability(Request $request, AvailabilityEngine $engine, LineBuilder $builder, AvailabilityPresenter $presenter): JsonResponse
    {
        $request->validate(['date' => ['required', 'date_format:Y-m-d']] + $this->itemRules());
        $branch = $this->branch($request);
        $lines = $builder->build($branch, $this->items($request), online: true);
        $day = $engine->dayWithNext($branch, $lines, $request->query('date'));

        return response()->json(['data' => $presenter->day($branch, $lines, $day)]);
    }

    /**
     * Hold a time while the client reviews.
     *
     * Optional: POST /bookings holds the time itself. Returns the totals and the payment options the client may pick.
     *
     * @bodyParam branch_id integer required Example: 1
     * @bodyParam items object[] required `[{service_id, variant_id?, addon_ids?, staff_id?}]`
     * @bodyParam starts_at string required UTC start from /availability. Example: 2026-12-01T08:00:00Z
     * @bodyParam replace_token string A previous hold token to release.
     */
    public function hold(Request $request, HoldSlot $hold, LineBuilder $builder, BookingPayments $payments): JsonResponse
    {
        $data = $request->validate(['starts_at' => ['required', 'date'], 'replace_token' => ['nullable', 'string', 'max:64']] + $this->itemRules());
        $branch = $this->branch($request);
        $lines = $builder->build($branch, $this->items($request), online: true);
        $result = $hold->handle($branch, $lines, $data['starts_at'], $data['replace_token'] ?? null);
        $slot = $result['slot'];
        $deposit = $payments->depositFor($lines, $slot);
        $currency = tenant()->currency ?? 'QAR';

        return response()->json(['data' => [
            'hold_token' => $result['token'],
            'expires_at' => $result['expires_at']->toIso8601ZuluString(),
            'starts_at' => $slot->startsAt()->toIso8601ZuluString(),
            'ends_at' => $slot->endsAt()->toIso8601ZuluString(),
            'total' => Money::of($slot->price(), $currency),
            'deposit' => Money::of($deposit, $currency),
            'payment_options' => $payments->options($slot->price(), $deposit, $branch->id),
        ]], 201);
    }

    /**
     * Book.
     *
     * Holds the slot (unless `hold_token` is given) and creates the booking. With `payment_preference` deposit|full
     * the booking is `pending_payment` and `payment.checkout_url` is the hosted payment page; the booking is
     * confirmed by the payment webhook. Send an `Idempotency-Key` header: retries replay the first response.
     *
     * @header Idempotency-Key 6f1c0e0a-2b5e-4a53-9a39-0d8f1b2a1f00
     *
     * @bodyParam branch_id integer required Example: 1
     * @bodyParam items object[] required `[{service_id, variant_id?, addon_ids?, staff_id?}]`
     * @bodyParam starts_at string required UTC start from /availability. Example: 2026-12-01T08:00:00Z
     * @bodyParam payment_preference string deposit|full|pay_at_salon. Example: pay_at_salon
     * @bodyParam hold_token string Token from POST /bookings/hold.
     * @bodyParam notes string Note for the salon.
     * @bodyParam look_reference string AI Studio look reference ("Book this look").
     */
    public function store(Request $request, HoldSlot $hold, CreateBooking $create, StartBookingPayment $pay, LineBuilder $builder): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'payment_preference' => ['nullable', 'in:deposit,full,pay_at_salon'],
            'hold_token' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'look_reference' => ['nullable', 'string', 'max:190'],
            'utm_source' => ['nullable', 'string', 'max:60'],
            'campaign_id' => ['nullable', 'integer'],
        ] + $this->itemRules());
        $client = $this->client($request);
        $branch = $this->branch($request);
        $lines = $builder->build($branch, $this->items($request), online: true);

        $token = $data['hold_token'] ?? null;
        $ownHold = false;
        if (! $token) {
            $token = $hold->handle($branch, $lines, $data['starts_at'])['token'];
            $ownHold = true;
        }

        try {
            $booking = $create->handle([
                'branch' => $branch,
                'lines' => $lines,
                'starts_at' => $data['starts_at'],
                'client' => $client,
                'source' => 'app',
                'hold_token' => $token,
                'payment_preference' => $data['payment_preference'] ?? null,
                'client_notes' => $data['notes'] ?? null,
                'look_reference' => $data['look_reference'] ?? null,
                'utm_source' => $data['utm_source'] ?? null,
                'campaign_id' => $data['campaign_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            if ($ownHold) {
                $hold->release($token);
            }
            throw $e;
        }

        if ($booking->status === BookingStatus::PendingPayment) {
            $pay->handle($booking, $booking->payment_preference, BookingLinks::confirmation($booking));
        }

        return (new BookingResource($this->load($booking)))->response()->setStatusCode(201);
    }

    /**
     * My bookings.
     *
     * @queryParam scope string upcoming|past|all (default all). Example: upcoming
     * @queryParam per_page integer Max 50. Example: 20
     */
    public function index(Request $request)
    {
        $request->validate(['scope' => ['nullable', 'in:upcoming,past,all'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $client = $this->client($request);
        $scope = $request->query('scope', 'all');

        $page = Booking::query()->where('client_id', $client->id)
            ->with(['branch', 'items.service', 'items.staff', 'items.variant'])
            ->when($scope === 'upcoming', fn ($q) => $q->where('ends_at', '>=', now())->whereNotIn('status', ['cancelled_by_client', 'cancelled_by_salon', 'no_show', 'completed'])->orderBy('starts_at'))
            ->when($scope === 'past', fn ($q) => $q->where(fn ($w) => $w->where('ends_at', '<', now())->orWhereIn('status', ['cancelled_by_client', 'cancelled_by_salon', 'no_show', 'completed']))->orderByDesc('starts_at'))
            ->when($scope === 'all', fn ($q) => $q->orderByDesc('starts_at'))
            ->paginate($request->integer('per_page', 20));

        return BookingResource::collection($page);
    }

    /** One booking. */
    public function show(Request $request, string $reference)
    {
        return new BookingResource($this->load($this->find($request, $reference)));
    }

    /**
     * Cancel.
     *
     * Inside the cancellation window the late-cancel fee is kept and the rest of any online payment is refunded.
     *
     * @bodyParam reason string Example: Feeling unwell
     */
    public function cancel(Request $request, string $reference, CancelBooking $cancel)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:250']]);
        $booking = $this->find($request, $reference);
        if (! in_array($booking->status, [BookingStatus::PendingPayment, BookingStatus::Confirmed], true)) {
            throw new DomainException(__('api.booking.cannot_cancel'));
        }

        return new BookingResource($this->load($cancel->handle($booking, 'client', $data['reason'] ?? null)));
    }

    /**
     * Times to move a booking to (the booking's own time does not block).
     *
     * @queryParam date string required Example: 2026-12-02
     */
    public function rescheduleAvailability(Request $request, string $reference, AvailabilityEngine $engine, AvailabilityPresenter $presenter): JsonResponse
    {
        $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'keep_staff' => ['nullable', 'boolean']]);
        $booking = $this->find($request, $reference)->load(['branch', 'items.service.branches']);
        $lines = BookingLines::fromBooking($booking, keepStaff: $request->boolean('keep_staff', true));
        $day = $engine->dayWithNext($booking->branch, $lines, $request->query('date'), ['ignore_booking_id' => $booking->id]);

        return response()->json(['data' => $presenter->day($booking->branch, $lines, $day, withPayment: false)]);
    }

    /**
     * Reschedule.
     *
     * @bodyParam starts_at string required UTC start. Example: 2026-12-02T09:00:00Z
     * @bodyParam keep_staff boolean Keep the same stylist (default true).
     */
    public function reschedule(Request $request, string $reference, RescheduleBooking $reschedule)
    {
        $data = $request->validate(['starts_at' => ['required', 'date'], 'keep_staff' => ['nullable', 'boolean']]);
        $booking = $this->find($request, $reference);
        $booking = $reschedule->handle($booking, $data['starts_at'], ['by' => 'client', 'keep_staff' => $request->boolean('keep_staff', true)]);

        return new BookingResource($this->load($booking));
    }

    // ------------------------------------------------------------------------------------------

    protected function client(Request $request): Client
    {
        return $request->user();
    }

    protected function find(Request $request, string $reference): Booking
    {
        return Booking::query()->where('client_id', $this->client($request)->id)->where('reference', strtoupper($reference))->firstOrFail();
    }

    protected function load(Booking $booking): Booking
    {
        return $booking->fresh(['branch', 'items.service', 'items.staff', 'items.variant']);
    }

    protected function branch(Request $request): Branch
    {
        return Branch::query()->active()->where('booking_enabled', true)->find($request->integer('branch_id'))
            ?? throw new DomainException(__('booking.errors.branch_unavailable'));
    }

    protected function itemRules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'items' => ['required_without:services', 'array', 'max:8'],
            'items.*.service_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.addon_ids' => ['nullable', 'array'],
            'items.*.addon_ids.*' => ['integer'],
            'items.*.staff_id' => ['nullable', 'integer'],
            'services' => ['required_without:items', 'array', 'max:8'],
            'services.*' => ['integer'],
            'staff_id' => ['nullable', 'integer'],
            'variants' => ['nullable', 'array'],
            'addons' => ['nullable', 'array'],
        ];
    }

    /** `items[]` (full form) or `services[]` + `staff_id` + `variants[sid]` + `addons[sid][]` (query-string form). */
    protected function items(Request $request): array
    {
        $raw = $request->input('items');
        if (! is_array($raw)) {
            $variants = (array) $request->input('variants', []);
            $addons = (array) $request->input('addons', []);
            $raw = array_map(fn ($id) => [
                'service_id' => (int) $id,
                'variant_id' => $variants[$id] ?? null,
                'addon_ids' => (array) ($addons[$id] ?? []),
                'staff_id' => $request->input('staff_id'),
            ], array_values((array) $request->input('services', [])));
        }

        return array_values(array_map(fn ($i) => [
            'service_id' => (int) ($i['service_id'] ?? 0),
            'variant_id' => ! empty($i['variant_id']) ? (int) $i['variant_id'] : null,
            'addon_ids' => array_map('intval', (array) ($i['addon_ids'] ?? [])),
            'staff_id' => ! empty($i['staff_id']) ? (int) $i['staff_id'] : null,
        ], array_slice($raw, 0, 8)));
    }
}
