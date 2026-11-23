<?php

namespace App\Http\Controllers\Site\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\BookingLinks;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\Payments\Models\Payment;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BookingFlowController extends Controller
{
    /** The booking wizard. Resolves the deep-link contract into the initial state. */
    public function show(Request $request, PublicCatalog $catalog)
    {
        $branches = $catalog->branches();
        $branch = $request->filled('branch') ? Branch::query()->where('slug', $request->query('branch'))->first() : null;
        if (! $branch && $branches->count() === 1) {
            $branch = Branch::find($branches->first()['id']);
        }

        $serviceSlugs = array_filter(explode(',', (string) $request->query('service', '')));
        $serviceIds = $serviceSlugs ? Service::query()->whereIn('slug', $serviceSlugs)->pluck('id')->map(fn ($id) => (int) $id)->all() : [];

        $attribution = array_filter([
            'look_reference' => $request->query('look'),
            'utm_source' => $request->query('utm_source'),
            'campaign_id' => is_numeric($request->query('campaign')) ? (int) $request->query('campaign') : null,
        ]);
        if ($attribution) {
            $request->session()->put('booking.attribution', $attribution);
        }
        $attribution = array_merge($request->session()->get('booking.attribution', []), $attribution);

        $client = $request->user('client');

        return view('site.booking.flow', [
            'state' => [
                'branches' => $branches,
                'branchId' => $branch?->id,
                'serviceIds' => $serviceIds,
                'staffId' => $request->integer('staff') ?: null,
                'attribution' => $attribution,
                'client' => $client ? ['name' => $client->name, 'phone' => $client->phone, 'email' => $client->email] : null,
                'catalog' => $branch ? $catalog->catalog($branch) : null,
                'locale' => app()->getLocale(),
                'currency' => tenant()->currency,
                'routes' => [
                    'catalog' => route('booking.api.catalog'), 'availability' => route('booking.api.availability'), 'hold' => route('booking.api.hold'),
                    'otpSend' => route('booking.api.otp.send'), 'otpVerify' => route('booking.api.otp.verify'), 'checkout' => route('booking.api.checkout'),
                    'waitlist' => route('booking.api.waitlist'), 'login' => route('client.login'),
                ],
            ],
        ]);
    }

    public function confirmation(Request $request, string $reference)
    {
        $booking = Booking::query()->where('reference', $reference)->with(['branch', 'items.service', 'items.staff', 'client'])->firstOrFail();
        $request->session()->put('booking_access.'.$booking->reference, true);

        $payment = Payment::query()->where('payable_type', $booking->getMorphClass())->where('payable_id', $booking->id)->where('amount', '>', 0)->latest('id')->first();

        return view('site.booking.confirmation', [
            'booking' => $booking,
            'payment' => $payment,
            'tz' => $booking->branch->timezone,
            'links' => [
                'manage' => BookingLinks::manage($booking), 'ics' => BookingLinks::ics($booking), 'google' => BookingLinks::google($booking), 'rebook' => BookingLinks::rebook($booking),
            ],
            'pending' => $booking->status === BookingStatus::PendingPayment,
        ]);
    }

    /** Back from the hosted payment page: hand over to the core payment-return flow (re-checks with the provider). */
    public function paymentReturn(Request $request, string $reference)
    {
        $booking = Booking::query()->where('reference', strtoupper($reference))->firstOrFail();
        $payment = Payment::query()->where('payable_type', $booking->getMorphClass())->where('payable_id', $booking->id)->where('amount', '>', 0)->latest('id')->firstOrFail();

        // Same browser that checked out → full confirmation page. Otherwise (e.g. paid on another device)
        // only the neutral payment status is shown; the webhook settles the booking either way.
        if (! $request->session()->get('booking_access.'.$booking->reference)) {
            return view('payments.result', ['payment' => $payment]);
        }

        return redirect()->route('payments.return', $payment->idempotency_key);
    }
}
