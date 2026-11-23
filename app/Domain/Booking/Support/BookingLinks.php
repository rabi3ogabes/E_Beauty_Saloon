<?php

namespace App\Domain\Booking\Support;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WaitlistEntry;
use Illuminate\Support\Facades\URL;

/**
 * Client-facing links on the salon's own domain. Signatures are relative (host independent) so links
 * built in queued jobs / console commands stay valid on custom domains.
 */
class BookingLinks
{
    public static function manage(Booking $booking): string
    {
        return self::onTenant(URL::signedRoute('booking.manage', ['reference' => $booking->reference], absolute: false));
    }

    public static function confirmation(Booking $booking): string
    {
        return self::onTenant(URL::signedRoute('booking.confirmation', ['reference' => $booking->reference], absolute: false));
    }

    /**
     * Where the hosted payment page sends the client back (then the core /payments/return flow).
     * Unsigned on purpose: some gateways append their own query parameters to the return URL.
     */
    public static function paymentReturn(Booking $booking): string
    {
        return self::onTenant(route('booking.payment.return', ['reference' => $booking->reference], absolute: false));
    }

    public static function ics(Booking $booking): string
    {
        return self::onTenant(URL::signedRoute('booking.ics', ['reference' => $booking->reference], absolute: false));
    }

    public static function review(Booking $booking): string
    {
        return self::onTenant(URL::signedRoute('marketing.review.show', ['reference' => $booking->reference], absolute: false)); // page owned by Marketing
    }

    public static function waitlistClaim(WaitlistEntry $entry, int $minutes = 30): string
    {
        return self::onTenant(URL::temporarySignedRoute('booking.waitlist.claim', now()->addMinutes($minutes), ['entry' => $entry->id], absolute: false));
    }

    /** One-tap rebook deep link: /book?branch=…&service=a,b&staff=… */
    public static function rebook(Booking $booking): string
    {
        $booking->loadMissing(['branch', 'items.service']);
        $staff = $booking->items->pluck('staff_id')->filter()->unique();

        return self::onTenant('/book?'.http_build_query(array_filter([
            'branch' => $booking->branch?->slug,
            'service' => $booking->items->map(fn ($i) => $i->service?->slug)->filter()->unique()->implode(','),
            'staff' => $staff->count() === 1 ? $staff->first() : null,
        ])));
    }

    public static function google(Booking $booking): string
    {
        $booking->loadMissing(['branch', 'items.service']);
        $fmt = fn ($d) => $d->copy()->utc()->format('Ymd\THis\Z');

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => self::title($booking),
            'dates' => $fmt($booking->starts_at).'/'.$fmt($booking->ends_at),
            'details' => __('booking.calendar.details', ['reference' => $booking->reference, 'url' => self::manage($booking)]),
            'location' => trim(($booking->branch?->name ?? '').' '.($booking->branch?->address ?? '')),
        ]);
    }

    public static function title(Booking $booking): string
    {
        return __('booking.calendar.title', ['services' => $booking->items->map(fn ($i) => $i->service?->name)->filter()->implode(' + '), 'salon' => tenant()?->name]);
    }

    protected static function onTenant(string $path): string
    {
        return tenant() ? tenant()->url($path) : url($path);
    }
}
