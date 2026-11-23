<?php

namespace App\Domain\Booking\Availability;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Support\Exceptions\DomainException;

/**
 * Turns raw request input into validated ServiceLines:
 *   [['service_id' => 1, 'variant_id' => null, 'addon_ids' => [], 'staff_id' => null, 'guest_name' => null], ...]
 */
class LineBuilder
{
    /** @return list<ServiceLine> */
    public function build(Branch $branch, array $items, bool $online = true): array
    {
        if ($items === [] || count($items) > 8) {
            throw new DomainException(__('booking.errors.services_required'));
        }

        $ids = collect($items)->pluck('service_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $services = Service::query()->whereIn('id', $ids)->with(['variants', 'addons', 'branches'])->get()->keyBy('id');

        $lines = [];
        foreach (array_values($items) as $item) {
            $service = $services->get((int) ($item['service_id'] ?? 0));
            if (! $service || ! $service->isActiveAt($branch) || ($online && ! $service->online_bookable)) {
                throw new DomainException(__('booking.errors.service_unavailable'));
            }
            $variant = null;
            if (! empty($item['variant_id'])) {
                $variant = $service->variants->firstWhere('id', (int) $item['variant_id'])
                    ?? throw new DomainException(__('booking.errors.service_unavailable'));
            }
            $addonIds = array_map('intval', (array) ($item['addon_ids'] ?? []));
            $addons = $service->addons->whereIn('id', $addonIds)->values();
            if ($addons->count() !== count(array_unique($addonIds))) {
                throw new DomainException(__('booking.errors.service_unavailable'));
            }
            $lines[] = new ServiceLine(
                service: $service,
                variant: $variant,
                addons: $addons,
                staffId: ! empty($item['staff_id']) ? (int) $item['staff_id'] : null,
                durationOverride: ! empty($item['duration_minutes']) ? (int) $item['duration_minutes'] : null,
                guestName: $item['guest_name'] ?? null,
            );
        }

        return $lines;
    }
}
