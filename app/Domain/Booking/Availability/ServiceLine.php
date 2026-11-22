<?php

namespace App\Domain\Booking\Availability;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceAddon;
use App\Domain\Catalog\Models\ServiceVariant;
use App\Domain\Team\Models\Staff;
use Illuminate\Support\Collection;

/**
 * One requested service in a booking: service (+ variant, add-ons) and an optional preferred staff
 * (null = "no preference"). Services of a booking are chained back-to-back in the given order.
 */
final class ServiceLine
{
    /** @param Collection<int, ServiceAddon> $addons */
    public function __construct(
        public readonly Service $service,
        public readonly ?ServiceVariant $variant = null,
        public readonly Collection $addons = new Collection,
        public readonly ?int $staffId = null,
        public readonly ?int $durationOverride = null,
        public readonly ?string $guestName = null,
    ) {}

    public function withStaff(?int $staffId): self
    {
        return new self($this->service, $this->variant, $this->addons, $staffId, $this->durationOverride, $this->guestName);
    }

    public function withDuration(?int $minutes): self
    {
        return new self($this->service, $this->variant, $this->addons, $this->staffId, $minutes, $this->guestName);
    }

    /** Service minutes for a given staff (staff override > branch override > base) + add-ons. */
    public function durationFor(Branch $branch, ?Staff $staff): int
    {
        if ($this->durationOverride) {
            return $this->durationOverride;
        }

        return $this->service->durationAt($branch, $staff, $this->variant) + (int) $this->addons->sum('duration_minutes');
    }

    /** Price in minor units for a given staff + add-ons. */
    public function priceFor(Branch $branch, ?Staff $staff): int
    {
        return $this->service->priceAt($branch, $staff, $this->variant) + (int) $this->addons->sum('price');
    }

    public function resourceTypes(): array
    {
        return array_values(array_filter((array) ($this->service->resource_types ?? [])));
    }

    public function cacheKey(): string
    {
        return implode(':', [
            $this->service->id, $this->variant?->id ?? 0, $this->addons->pluck('id')->sort()->implode(','),
            $this->staffId ?? 'any', $this->durationOverride ?? 0,
        ]);
    }
}
