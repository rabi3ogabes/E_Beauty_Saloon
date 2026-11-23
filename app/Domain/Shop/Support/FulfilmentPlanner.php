<?php

namespace App\Domain\Shop\Support;

use App\Domain\Branches\Models\Branch;
use App\Domain\Shop\Models\DeliveryZone;
use App\Support\Exceptions\DomainException;
use App\Support\Tenancy\BranchScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Delivery zones, fees, same-day slots and pickup ready-time estimates. */
class FulfilmentPlanner
{
    /** @var array<int, ?Branch> */
    protected array $branches = [];

    public function zones(): Collection
    {
        return DeliveryZone::withoutGlobalScope(BranchScope::class)->active()->orderBy('sort')->get();
    }

    public function pickupBranches(): Collection
    {
        return Branch::withoutGlobalScopes()->where('tenant_id', tenant()?->id)->whereNull('deleted_at')
            ->where('status', 'active')->where('shop_pickup_enabled', true)->orderBy('sort')->get();
    }

    /** Explicit zone > map pin inside polygon > area name. */
    public function resolveZone(?int $zoneId = null, ?float $lat = null, ?float $lng = null, ?string $area = null): ?DeliveryZone
    {
        $zones = $this->zones();
        if ($zoneId && ($z = $zones->firstWhere('id', $zoneId))) {
            return $z;
        }

        return $zones->first(fn (DeliveryZone $z) => $z->containsPoint($lat, $lng))
            ?? $zones->first(fn (DeliveryZone $z) => $z->coversArea($area));
    }

    /**
     * Delivery fee for a merchandise amount (after discount). Enforces the zone minimum order.
     */
    public function deliveryFee(DeliveryZone $zone, string $fulfilment, int $merchandise, bool $freeShipping = false): int
    {
        if ($merchandise < (int) $zone->min_order) {
            throw new DomainException(__('shop.errors.min_order', ['amount' => money((int) $zone->min_order), 'zone' => $zone->name]));
        }
        if ($fulfilment === 'same_day') {
            if (! $this->sameDayOpen($zone)) {
                throw new DomainException(__('shop.errors.same_day_closed', ['time' => $this->cutoff($zone)]));
            }

            return (int) $zone->fee + (int) $zone->same_day_fee;
        }
        if ($freeShipping || ($zone->free_shipping_threshold !== null && $merchandise >= (int) $zone->free_shipping_threshold)) {
            return 0;
        }

        return (int) $zone->fee;
    }

    public function cutoff(DeliveryZone $zone): string
    {
        return $zone->same_day_cutoff ?: (string) ShopSettings::get('same_day_cutoff');
    }

    public function sameDayOpen(DeliveryZone $zone, ?CarbonInterface $now = null): bool
    {
        if (! $zone->same_day_enabled) {
            return false;
        }
        $tz = $this->branch($zone->branch_id)?->timezone ?? 'Asia/Qatar';
        $local = CarbonImmutable::instance($now ?? now())->setTimezone($tz);

        return $local->format('H:i') < $this->cutoff($zone);
    }

    /** Two-hour same-day slots between now + 90 minutes and the branch closing time (branch local time). */
    public function sameDaySlots(DeliveryZone $zone, ?CarbonInterface $now = null): array
    {
        if (! $this->sameDayOpen($zone, $now)) {
            return [];
        }
        $branch = $this->branch($zone->branch_id);
        $tz = $branch?->timezone ?? 'Asia/Qatar';
        $local = CarbonImmutable::instance($now ?? now())->setTimezone($tz);
        $hours = $branch?->hoursFor($local) ?? ['open' => '10:00', 'close' => '22:00'];
        $close = $local->setTimeFromTimeString($hours['close']);
        $start = $local->addMinutes(90)->ceilHour();
        $slots = [];
        while ($start->addHours(2)->lte($close) && count($slots) < 6) {
            $slots[] = $start->format('Y-m-d H:i').'-'.$start->addHours(2)->format('H:i');
            $start = $start->addHours(2);
        }

        return $slots;
    }

    public function isValidSlot(DeliveryZone $zone, ?string $slot): bool
    {
        return $slot !== null && in_array($slot, $this->sameDaySlots($zone), true);
    }

    /** When a pickup order will be ready, honoring branch opening hours. Returns UTC. */
    public function pickupEstimate(Branch $branch, ?CarbonInterface $from = null): CarbonImmutable
    {
        $tz = $branch->timezone ?: 'Asia/Qatar';
        $prep = (int) ShopSettings::get('pickup_prep_minutes');
        $at = CarbonImmutable::instance($from ?? now())->setTimezone($tz)->addMinutes($prep);

        for ($i = 0; $i < 8; $i++) {
            $hours = $branch->hoursFor($at);
            if ($hours) {
                $open = $at->setTimeFromTimeString($hours['open']);
                $close = $at->setTimeFromTimeString($hours['close']);
                if ($at->lt($open)) {
                    $at = $open->addMinutes(min($prep, 60));
                }
                if ($at->lte($close)) {
                    return $at->utc();
                }
            }
            $at = $at->addDay()->startOfDay();
        }

        return $at->utc();
    }

    /** Standard delivery ETA. */
    public function deliveryEstimate(DeliveryZone $zone, string $fulfilment, ?string $slot = null): CarbonImmutable
    {
        if ($fulfilment === 'same_day' && $slot) {
            $tz = $this->branch($zone->branch_id)?->timezone ?? 'Asia/Qatar';
            [$date, $range] = explode(' ', $slot, 2);

            return CarbonImmutable::parse($date.' '.explode('-', $range)[1], $tz)->utc();
        }

        return CarbonImmutable::now()->addHours(max(1, (int) $zone->eta_hours));
    }

    protected function branch(?int $id): ?Branch
    {
        return $id ? ($this->branches[$id] ??= Branch::withoutGlobalScopes()->find($id)) : null;
    }
}
