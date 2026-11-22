<?php

namespace App\Http\Controllers\Site\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Models\Client;
use Illuminate\Http\Request;

/** "My bookings" section of the client account (registered in AccountSections). */
class AccountBookingsSection
{
    public function render(Client $client, Request $request): string
    {
        $base = Booking::query()->where('client_id', $client->id)->with(['branch', 'items.service', 'items.staff']);

        $upcoming = (clone $base)->where('starts_at', '>=', now()->subHours(3))
            ->whereIn('status', array_map(fn ($s) => $s->value, [BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::InService]))
            ->orderBy('starts_at')->limit(20)->get();
        $past = (clone $base)->whereNotIn('id', $upcoming->pluck('id'))->orderByDesc('starts_at')->limit(20)->get();

        return view('site.booking.account', compact('client', 'upcoming', 'past'))->render();
    }
}
