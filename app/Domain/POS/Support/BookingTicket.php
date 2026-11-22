<?php

namespace App\Domain\POS\Support;

use App\Domain\Booking\Models\Booking;

/** Turns a checked-in / confirmed booking into a POS ticket: its services, staff and the deposit already paid. */
class BookingTicket
{
    /** @return array{booking_id: int, client_id: ?int, reference: string, deposit: int, lines: array<int, array<string, mixed>>} */
    public static function from(Booking $booking): array
    {
        $booking->loadMissing(['items.service', 'items.staff']);

        return [
            'booking_id' => $booking->id,
            'client_id' => $booking->client_id,
            'reference' => $booking->reference,
            'deposit' => $booking->sale_id ? 0 : (int) $booking->amount_paid,
            'lines' => $booking->items->map(fn ($item) => [
                'type' => 'service',
                'id' => $item->service_id,
                'name' => (string) ($item->service?->name ?? '#'.$item->service_id),
                'qty' => 1,
                'unit_price' => (int) $item->price,
                'staff_id' => $item->staff_id,
                'booking_item_id' => $item->id,
            ])->values()->all(),
        ];
    }
}
