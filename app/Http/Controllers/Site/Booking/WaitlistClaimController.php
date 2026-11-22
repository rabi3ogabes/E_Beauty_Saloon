<?php

namespace App\Http\Controllers\Site\Booking;

use App\Domain\Booking\Actions\ClaimWaitlistOffer;
use App\Domain\Booking\Actions\StartBookingPayment;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\WaitlistEntry;
use App\Domain\Booking\Support\BookingLinks;
use App\Domain\Catalog\Models\Service;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\Request;

/** 30-minute signed claim link sent by WhatsApp when a waitlisted slot frees up. */
class WaitlistClaimController extends Controller
{
    public function show(WaitlistEntry $entry)
    {
        $entry->load(['branch', 'client']);
        $services = Service::whereIn('id', $entry->service_ids ?? [])->get();

        return view('site.booking.waitlist-claim', ['entry' => $entry, 'services' => $services, 'tz' => $entry->branch->timezone,
            'available' => $entry->status === 'offered' && $entry->offer_expires_at?->isFuture()]);
    }

    public function store(Request $request, WaitlistEntry $entry, ClaimWaitlistOffer $claim, StartBookingPayment $pay)
    {
        try {
            $booking = $claim->handle($entry);
        } catch (DomainException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }
        $request->session()->put('booking_access.'.$booking->reference, true);
        if ($booking->status === BookingStatus::PendingPayment) {
            return redirect()->away($pay->handle($booking, $booking->payment_preference, BookingLinks::confirmation($booking))->checkout_url);
        }

        return redirect(BookingLinks::confirmation($booking));
    }
}
