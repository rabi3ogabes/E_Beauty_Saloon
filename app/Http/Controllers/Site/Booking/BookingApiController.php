<?php

namespace App\Http\Controllers\Site\Booking;

use App\Domain\Booking\Actions\CreateBooking;
use App\Domain\Booking\Actions\HoldSlot;
use App\Domain\Booking\Actions\JoinWaitlist;
use App\Domain\Booking\Actions\StartBookingPayment;
use App\Domain\Booking\Availability\AvailabilityEngine;
use App\Domain\Booking\Availability\LineBuilder;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Support\BookingLinks;
use App\Domain\Booking\Support\BookingPayments;
use App\Domain\Branches\Models\Branch;
use App\Domain\CRM\Actions\SendClientOtp;
use App\Domain\CRM\Actions\VerifyClientOtp;
use App\Domain\Team\Models\Staff;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** JSON endpoints behind the /book wizard. Business rules live in the Actions / engine. */
class BookingApiController extends Controller
{
    public function catalog(Request $request, PublicCatalog $catalog): JsonResponse
    {
        return response()->json($catalog->catalog($this->branch($request)));
    }

    public function availability(Request $request, AvailabilityEngine $engine, LineBuilder $builder): JsonResponse
    {
        $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $branch = $this->branch($request);
        $lines = $this->lines($request, $builder, $branch);
        $day = $engine->dayWithNext($branch, $lines, $request->query('date'));

        $staffIds = collect($day->slots)->flatMap(fn ($s) => $s->staffIds())->unique()->all();
        $names = Staff::withoutGlobalScopes()->whereIn('id', $staffIds)->get()->mapWithKeys(fn ($s) => [$s->id => $s->name]);

        return response()->json(array_merge($day->toArray(), [
            'date_label' => CarbonImmutable::parse($day->date, $branch->timezone)->translatedFormat('l j F'),
            'next_available_label' => $day->nextAvailableDate ? CarbonImmutable::parse($day->nextAvailableDate, $branch->timezone)->translatedFormat('l j F') : null,
            'staff' => $names,
        ]));
    }

    public function hold(Request $request, HoldSlot $hold, BookingPayments $payments, LineBuilder $builder): JsonResponse
    {
        $data = $request->validate(['starts_at' => ['required', 'date']]);
        $branch = $this->branch($request);
        $lines = $this->lines($request, $builder, $branch);
        // One active hold per browser session: picking another time releases the previous one.
        $result = $hold->handle($branch, $lines, $data['starts_at'], $request->session()->get('booking.hold_token'));
        $request->session()->put('booking.hold_token', $result['token']);
        $slot = $result['slot'];

        $total = $slot->price();
        $deposit = $payments->depositFor($lines, $slot);
        $options = $payments->options($total, $deposit, $branch->id);

        return response()->json([
            'token' => $result['token'],
            'expires_at' => $result['expires_at']->toIso8601ZuluString(),
            'slot' => $slot->toArray($branch->timezone),
            'total' => $total, 'total_label' => money($total),
            'deposit' => $deposit, 'deposit_label' => money($deposit),
            'payment_options' => array_map(fn ($o) => [
                'key' => $o, 'label' => __('booking.payment_preferences.'.$o),
                'amount_label' => money($o === 'deposit' ? $deposit : ($o === 'full' ? $total : 0)),
                'hint' => __('booking.flow.payment_hint.'.$o, ['amount' => money($o === 'deposit' ? $deposit : $total)]),
            ], $options),
        ]);
    }

    public function sendOtp(Request $request, SendClientOtp $send): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $otp = $send->handle($data['phone']);

        return response()->json(['phone' => $otp->phone, 'message' => __('account.code_sent')]);
    }

    public function verifyOtp(Request $request, VerifyClientOtp $verify): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string'], 'code' => ['required', 'digits:6'], 'name' => ['nullable', 'string', 'max:100'], 'email' => ['nullable', 'email', 'max:150']]);
        $client = $verify->handle($data['phone'], $data['code'], $data['name'] ?? null, 'website');
        if (! empty($data['email']) && ! $client->email) {
            $client->update(['email' => $data['email']]);
        }
        Auth::guard('client')->login($client, remember: true);
        $request->session()->regenerate();

        return response()->json(['client' => ['name' => $client->name, 'phone' => $client->phone, 'email' => $client->email], 'csrf' => csrf_token()]);
    }

    public function checkout(Request $request, CreateBooking $create, StartBookingPayment $pay, LineBuilder $builder): JsonResponse
    {
        $client = $request->user('client');
        if (! $client || (int) $client->tenant_id !== (int) tenant()->id) {
            return response()->json(['message' => __('booking.errors.verify_phone')], 401);
        }
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'hold_token' => ['nullable', 'string', 'max:64'],
            'payment_preference' => ['nullable', 'in:deposit,full,pay_at_salon'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'look_reference' => ['nullable', 'string', 'max:190'],
            'utm_source' => ['nullable', 'string', 'max:60'],
            'campaign_id' => ['nullable', 'integer'],
        ]);
        $updates = array_filter(['name' => $client->name === __('account.guest') ? ($data['name'] ?? null) : null, 'email' => $client->email ? null : ($data['email'] ?? null)]);
        if ($updates) {
            $client->update($updates);
        }

        $branch = $this->branch($request);
        $attribution = $request->session()->get('booking.attribution', []);
        $booking = $create->handle([
            'branch' => $branch,
            'lines' => $this->lines($request, $builder, $branch),
            'starts_at' => $data['starts_at'],
            'client' => $client,
            'source' => 'website',
            'hold_token' => $data['hold_token'] ?? null,
            'payment_preference' => $data['payment_preference'] ?? null,
            'client_notes' => $data['notes'] ?? null,
            'look_reference' => $data['look_reference'] ?? ($attribution['look_reference'] ?? null),
            'utm_source' => $data['utm_source'] ?? ($attribution['utm_source'] ?? null),
            'campaign_id' => $data['campaign_id'] ?? ($attribution['campaign_id'] ?? null),
        ]);
        $request->session()->forget('booking.attribution');
        $request->session()->put('booking_access.'.$booking->reference, true);

        $confirmation = BookingLinks::confirmation($booking);
        if ($booking->status === BookingStatus::PendingPayment) {
            $payment = $pay->handle($booking, $booking->payment_preference, $confirmation);

            return response()->json(['reference' => $booking->reference, 'redirect' => $payment->checkout_url, 'payment' => true]);
        }

        return response()->json(['reference' => $booking->reference, 'redirect' => $confirmation, 'payment' => false]);
    }

    public function waitlist(Request $request, JoinWaitlist $join): JsonResponse
    {
        $client = $request->user('client');
        if (! $client || (int) $client->tenant_id !== (int) tenant()->id) {
            return response()->json(['message' => __('booking.errors.verify_phone')], 401);
        }
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'time_window' => ['nullable', 'in:any,morning,afternoon,evening']]);
        $branch = $this->branch($request);
        $items = $this->items($request);
        $staff = collect($items)->pluck('staff_id')->filter()->unique();
        $join->handle($client, $branch, collect($items)->pluck('service_id')->all(), $data['date'], $data['date'], $staff->count() === 1 ? (int) $staff->first() : null, $data['time_window'] ?? 'any');

        return response()->json(['message' => __('booking.flow.waitlist_joined')]);
    }

    // ------------------------------------------------------------------------------------------

    protected function branch(Request $request): Branch
    {
        $branch = Branch::query()->active()->where('booking_enabled', true)->find((int) $request->input('branch'));
        if (! $branch) {
            throw new DomainException(__('booking.errors.branch_unavailable'));
        }

        return $branch;
    }

    protected function items(Request $request): array
    {
        $items = $request->input('items');
        if (is_string($items)) {
            $items = json_decode($items, true);
        }
        if (! is_array($items) || $items === []) {
            throw new DomainException(__('booking.errors.services_required'));
        }

        return array_values(array_map(fn ($i) => [
            'service_id' => (int) ($i['service_id'] ?? 0),
            'variant_id' => ! empty($i['variant_id']) ? (int) $i['variant_id'] : null,
            'addon_ids' => array_map('intval', (array) ($i['addon_ids'] ?? [])),
            'staff_id' => ! empty($i['staff_id']) ? (int) $i['staff_id'] : null,
        ], array_slice($items, 0, 8)));
    }

    protected function lines(Request $request, LineBuilder $builder, Branch $branch): array
    {
        return $builder->build($branch, $this->items($request), online: true);
    }
}
