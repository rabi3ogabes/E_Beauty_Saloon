<?php

namespace App\Http\Controllers\Site\Booking;

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\RescheduleBooking;
use App\Domain\Booking\Actions\StartBookingPayment;
use App\Domain\Booking\Availability\AvailabilityEngine;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\BookingLines;
use App\Domain\Booking\Support\BookingLinks;
use App\Domain\Booking\Support\BookingSettings;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Client "manage booking" page: opened from the signed link in messages (/booking/{reference}?signature=…)
 * or by the signed-in client who owns it. A valid signature grants access for the session.
 */
class ManageBookingController extends Controller
{
    public function show(Request $request, string $reference, PublicCatalog $catalog)
    {
        $booking = $this->authorized($request, $reference);
        $booking->load(['branch', 'items.service', 'items.staff', 'history']);
        $policy = $this->policy($booking);

        return view('site.booking.manage', [
            'booking' => $booking,
            'tz' => $booking->branch->timezone,
            'policy' => $policy,
            'dates' => $policy['can_reschedule'] ? $catalog->dates($booking->branch) : [],
            'links' => ['ics' => BookingLinks::ics($booking), 'google' => BookingLinks::google($booking), 'rebook' => BookingLinks::rebook($booking)],
        ]);
    }

    public function slots(Request $request, string $reference, AvailabilityEngine $engine)
    {
        $booking = $this->authorized($request, $reference);
        $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $booking->load(['branch', 'items.service.branches']);
        $lines = BookingLines::fromBooking($booking, keepStaff: $request->boolean('keep_staff', true));
        $day = $engine->dayWithNext($booking->branch, $lines, $request->query('date'), ['ignore_booking_id' => $booking->id]);

        return response()->json(array_merge($day->toArray(), [
            'next_available_label' => $day->nextAvailableDate ? CarbonImmutable::parse($day->nextAvailableDate, $booking->branch->timezone)->translatedFormat('l j F') : null,
        ]));
    }

    public function reschedule(Request $request, string $reference, RescheduleBooking $reschedule)
    {
        $booking = $this->authorized($request, $reference);
        $data = $request->validate(['starts_at' => ['required', 'date'], 'keep_staff' => ['nullable', 'boolean']]);
        try {
            $reschedule->handle($booking, $data['starts_at'], ['by' => 'client', 'keep_staff' => $request->boolean('keep_staff', true)]);
        } catch (DomainException $e) {
            return $request->expectsJson() ? response()->json(['message' => $e->getMessage()], 422) : back()->withErrors(['booking' => $e->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['message' => __('booking.manage.rescheduled')]) : back()->with('status', __('booking.manage.rescheduled'));
    }

    public function cancel(Request $request, string $reference, CancelBooking $cancel)
    {
        $booking = $this->authorized($request, $reference);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:250']]);
        try {
            $cancel->handle($booking, 'client', $data['reason'] ?? null);
        } catch (DomainException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return back()->with('status', __('booking.manage.cancelled'));
    }

    public function pay(Request $request, string $reference, StartBookingPayment $pay)
    {
        $booking = $this->authorized($request, $reference);
        try {
            $payment = $pay->handle($booking, $booking->status === BookingStatus::PendingPayment ? $booking->payment_preference : 'full', BookingLinks::confirmation($booking));
        } catch (DomainException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()->away($payment->checkout_url);
    }

    public function ics(string $reference)
    {
        $booking = Booking::query()->where('reference', $reference)->with(['branch', 'items.service', 'items.staff'])->firstOrFail();
        $fmt = fn ($d) => $d->copy()->utc()->format('Ymd\THis\Z');
        $esc = fn (?string $v) => str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], (string) $v);
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Luxe Salon OS//Booking//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$booking->reference.'-'.$booking->id.'@'.request()->getHost(),
            'DTSTAMP:'.$fmt(now()),
            'DTSTART:'.$fmt($booking->starts_at),
            'DTEND:'.$fmt($booking->ends_at),
            'SUMMARY:'.$esc(BookingLinks::title($booking)),
            'DESCRIPTION:'.$esc(__('booking.calendar.details', ['reference' => $booking->reference, 'url' => BookingLinks::manage($booking)])),
            'LOCATION:'.$esc(trim($booking->branch->name.' '.$booking->branch->address)),
            'STATUS:'.($booking->status->isCancelled() ? 'CANCELLED' : 'CONFIRMED'),
            'BEGIN:VALARM', 'TRIGGER:-PT2H', 'ACTION:DISPLAY', 'DESCRIPTION:'.$esc(BookingLinks::title($booking)), 'END:VALARM',
            'END:VEVENT', 'END:VCALENDAR',
        ];

        return response(implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="booking-'.$booking->reference.'.ics"',
        ]);
    }

    // ------------------------------------------------------------------------------------------

    protected function authorized(Request $request, string $reference): Booking
    {
        $booking = Booking::query()->where('reference', strtoupper($reference))->firstOrFail();
        $client = $request->user('client');
        $owns = $client && (int) $client->tenant_id === (int) $booking->tenant_id && (int) $client->id === (int) $booking->client_id;

        if ($request->hasValidRelativeSignature()) {
            $request->session()->put('booking_access.'.$booking->reference, true);
        } elseif (! $owns && ! $request->session()->get('booking_access.'.$booking->reference)) {
            abort($client ? 403 : 404);
        }

        return $booking;
    }

    protected function policy(Booking $booking): array
    {
        $active = in_array($booking->status, [BookingStatus::PendingPayment, BookingStatus::Confirmed], true) && $booking->starts_at->isFuture();
        $windowHours = BookingSettings::get('cancellation_window_hours');
        $insideWindow = now()->diffInMinutes($booking->starts_at, false) < $windowHours * 60;
        $limit = BookingSettings::get('reschedule_limit');

        return [
            'can_cancel' => $active,
            'late' => $insideWindow,
            'late_fee' => $insideWindow ? (int) min($booking->amount_paid, round($booking->amount_paid * BookingSettings::get('late_cancel_fee_percent') / 100)) : 0,
            'can_reschedule' => $active && ! $insideWindow && $booking->reschedule_count < $limit,
            'reschedules_left' => max(0, $limit - $booking->reschedule_count),
            'window_hours' => $windowHours,
            'can_pay' => in_array($booking->status, [BookingStatus::PendingPayment, BookingStatus::Confirmed], true) && $booking->balanceDue() > 0 && $booking->payment_preference !== 'pay_at_salon',
        ];
    }
}
