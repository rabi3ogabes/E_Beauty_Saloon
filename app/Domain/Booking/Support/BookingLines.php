<?php

namespace App\Domain\Booking\Support;

use App\Domain\Booking\Availability\ServiceLine;
use App\Domain\Booking\Models\Booking;
use App\Domain\Catalog\Models\ServiceAddon;
use App\Domain\Catalog\Models\ServiceVariant;

/** Rebuilds engine ServiceLines from an existing booking's items (reschedule / move / rebook). */
class BookingLines
{
    /**
     * @param  array<int, ?int>  $staffOverrides  item index => staff id (null = any)
     * @param  array<int, int>  $durationOverrides  item index => minutes
     * @return list<ServiceLine>
     */
    public static function fromBooking(Booking $booking, bool $keepStaff = true, array $staffOverrides = [], array $durationOverrides = []): array
    {
        $booking->loadMissing(['items.service.branches']);
        $lines = [];
        foreach ($booking->items->values() as $i => $item) {
            $addonIds = (array) ($item->addon_ids ?? []);
            $staffId = array_key_exists($i, $staffOverrides) ? $staffOverrides[$i] : ($keepStaff ? $item->staff_id : null);
            $baseDuration = $item->duration_minutes;
            $lines[] = new ServiceLine(
                service: $item->service,
                variant: $item->service_variant_id ? ServiceVariant::find($item->service_variant_id) : null,
                addons: $addonIds ? ServiceAddon::whereIn('id', $addonIds)->get() : collect(),
                staffId: $staffId ? (int) $staffId : null,
                durationOverride: $durationOverrides[$i] ?? ($keepStaff ? $baseDuration : null),
                guestName: $item->guest_name,
            );
        }

        return $lines;
    }
}
