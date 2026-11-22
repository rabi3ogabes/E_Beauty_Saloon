<?php

namespace App\Domain\Booking\Availability;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\Resource;
use App\Domain\Team\Models\Staff;
use App\Domain\Team\Models\StaffSchedule;
use App\Domain\Team\Models\TimeOff;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The availability engine: a deterministic service that answers "when can these services be booked?".
 *
 * Considers branch hours + holidays (branch timezone), staff schedules + breaks + approved time off,
 * blocking bookings (items incl. buffers, across every branch the staff works at), active slot holds,
 * service durations + buffers, staff skill overrides (price/duration), required resource types,
 * minimum notice, max days ahead and the slot interval. Multi-service requests are chained
 * back-to-back, preferring a single staff member for all services.
 *
 * Internally everything is UTC unix seconds. Options:
 *  - now: CarbonInterface (default now())          - online: bool (default true; only is_bookable staff)
 *  - enforce_notice / enforce_max_days: bool (true) - cache: bool (true)
 *  - ignore_hold_token: string (own holds don't block) - ignore_booking_id: int (rescheduling)
 */
class AvailabilityEngine
{
    public function __construct(protected AvailabilityCache $cache) {}

    /** @param list<ServiceLine> $lines */
    public function day(Branch $branch, array $lines, CarbonInterface|string $date, array $options = []): DayAvailability
    {
        $date = $this->localDate($branch, $date);
        $tz = $this->tz($branch);
        $now = $this->now($options);
        $branch->load('holidays');

        if (! $this->dateAllowed($branch, $date, $options)) {
            return new DayAvailability($date->toDateString(), [], true, $tz);
        }

        $closed = $branch->hoursFor($date) === null;
        $packed = $closed ? [] : $this->cachedDay($branch, $lines, $date, $options);
        $minStart = ($options['enforce_notice'] ?? true) ? $now->getTimestamp() + BookingSettings::get('min_notice_minutes') * 60 : $now->getTimestamp();

        $slots = [];
        foreach ($packed as $p) {
            if ($p[0] >= $minStart) {
                $slots[] = Slot::unpack($p);
            }
        }

        return new DayAvailability($date->toDateString(), $slots, $closed, $tz);
    }

    /** Day + (when empty) the next date that has at least one slot. */
    public function dayWithNext(Branch $branch, array $lines, CarbonInterface|string $date, array $options = []): DayAvailability
    {
        $day = $this->day($branch, $lines, $date, $options);
        if ($day->isEmpty()) {
            $day->nextAvailableDate = $this->nextAvailableDate($branch, $lines, CarbonImmutable::parse($day->date, $this->tz($branch))->addDay(), $options);
        }

        return $day;
    }

    /** @return list<DayAvailability> */
    public function range(Branch $branch, array $lines, CarbonInterface|string $from, CarbonInterface|string $to, array $options = []): array
    {
        $out = [];
        $cursor = $this->localDate($branch, $from);
        $end = $this->localDate($branch, $to);
        while ($cursor->lte($end)) {
            $out[] = $this->day($branch, $lines, $cursor, $options);
            $cursor = $cursor->addDay();
        }

        return $out;
    }

    public function nextAvailableDate(Branch $branch, array $lines, CarbonInterface|string $from, array $options = []): ?string
    {
        $cursor = $this->localDate($branch, $from);
        $limit = $this->today($branch, $options)->addDays(BookingSettings::get('max_days_ahead'));
        if (! ($options['enforce_max_days'] ?? true)) {
            $limit = $cursor->addDays(90);
        }
        while ($cursor->lte($limit)) {
            if (! $this->day($branch, $lines, $cursor, $options)->isEmpty()) {
                return $cursor->toDateString();
            }
            $cursor = $cursor->addDay();
        }

        return null;
    }

    /**
     * Validate one exact start time (UTC) and return the assignment, or null when it cannot be booked.
     * Always computed fresh (never cached) — use it right before writing.
     */
    public function quote(Branch $branch, array $lines, CarbonInterface $start, array $options = []): ?Slot
    {
        $start = CarbonImmutable::instance($start)->utc();
        $now = $this->now($options);
        if (($options['enforce_notice'] ?? true) && $start->getTimestamp() < $now->getTimestamp() + BookingSettings::get('min_notice_minutes') * 60) {
            return null;
        }
        $date = $start->setTimezone($this->tz($branch))->startOfDay();
        if (! $this->dateAllowed($branch, $date, $options)) {
            return null;
        }
        $branch->load('holidays');
        $ctx = $this->context($branch, $lines, $date, $date, $options);
        $day = $this->prepareDay($ctx, $date);

        return $day ? $this->tryChain($ctx, $day, $start->getTimestamp()) : null;
    }

    // ------------------------------------------------------------------------------------------

    protected function cachedDay(Branch $branch, array $lines, CarbonImmutable $date, array $options): array
    {
        $compute = function () use ($branch, $lines, $date, $options) {
            $ctx = $this->context($branch, $lines, $date, $date, $options);

            return array_map(fn (Slot $s) => $s->pack(), $this->computeDay($ctx, $date));
        };

        $cacheable = ($options['cache'] ?? true) && empty($options['ignore_hold_token']) && empty($options['ignore_booking_id']);
        if (! $cacheable) {
            return $compute();
        }

        $hash = md5(implode('|', array_map(fn (ServiceLine $l) => $l->cacheKey(), $lines)).'|'.(int) ($options['online'] ?? true).'|'.BookingSettings::slotInterval());

        return $this->cache->remember((int) $branch->tenant_id, (int) $branch->id, $date->toDateString(), $hash, $compute);
    }

    /** @return list<Slot> */
    protected function computeDay(array $ctx, CarbonImmutable $date): array
    {
        $day = $this->prepareDay($ctx, $date);
        if (! $day) {
            return [];
        }

        $interval = BookingSettings::slotInterval() * 60;
        $midnight = $day['midnight'];
        $t = $midnight + (int) ceil(($day['open'] - $midnight) / $interval) * $interval;
        $slots = [];
        for (; $t < $day['close']; $t += $interval) {
            if ($slot = $this->tryChain($ctx, $day, $t)) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * Free intervals per staff for one local date (work windows ∩ branch hours − breaks − time off − busy − holds).
     */
    protected function prepareDay(array $ctx, CarbonImmutable $date): ?array
    {
        /** @var Branch $branch */
        $branch = $ctx['branch'];
        $hours = $branch->hoursFor($date);
        if (! $hours) {
            return null;
        }
        $ds = $date->toDateString();
        $open = $this->ts($ds, $hours['open'], $ctx['tz']);
        $close = $this->ts($ds, $hours['close'], $ctx['tz']);
        if ($close <= $open) {
            $close += 86400; // closes after midnight
        }
        $closedParts = [];
        foreach ($hours['breaks'] ?? [] as $br) {
            $closedParts[] = [$this->ts($ds, $br[0], $ctx['tz']), $this->ts($ds, $br[1], $ctx['tz'])];
        }

        $weekday = $date->dayOfWeek;
        $free = [];
        foreach ($ctx['staff'] as $staffId => $staff) {
            $windows = [];
            if ($ctx['hasSchedules'][$staffId] ?? false) {
                foreach ($ctx['schedules'][$staffId][$weekday] ?? [] as $row) {
                    $s = max($open, $this->ts($ds, $row['starts_at'], $ctx['tz']));
                    $e = min($close, $this->ts($ds, $row['ends_at'], $ctx['tz']));
                    if ($e > $s) {
                        $windows[] = [$s, $e];
                    }
                    foreach ($row['breaks'] as $br) {
                        $closedParts[] = [$this->ts($ds, $br[0], $ctx['tz']), $this->ts($ds, $br[1], $ctx['tz']), $staffId];
                    }
                }
            } else {
                $windows[] = [$open, $close]; // no schedule configured at all: follow branch hours
            }
            if (! $windows) {
                continue;
            }
            $busy = array_merge(
                array_values(array_map(fn ($p) => [$p[0], $p[1]], array_filter($closedParts, fn ($p) => ! isset($p[2]) || $p[2] === $staffId))),
                $ctx['busy'][$staffId] ?? [],
            );
            $free[$staffId] = $this->subtract($windows, $busy);
        }

        return ['open' => $open, 'close' => $close, 'midnight' => $this->ts($ds, '00:00', $ctx['tz']), 'free' => $free];
    }

    /** Try to place every line starting at $t. */
    protected function tryChain(array $ctx, array $day, int $t): ?Slot
    {
        $lines = $ctx['lines'];

        // 1) Prefer one staff member for every service.
        $common = null;
        foreach ($ctx['candidates'] as $ids) {
            $common = $common === null ? $ids : array_values(array_intersect($common, $ids));
        }
        foreach ($common ?? [] as $staffId) {
            if ($slot = $this->place($ctx, $day, $t, fn () => [$staffId])) {
                return $slot;
            }
        }
        if (count($lines) === 1) {
            return null; // single service: nothing else to try
        }

        // 2) Greedy chain, preferring whoever did the previous service.
        return $this->place($ctx, $day, $t, function (int $i, ?int $prev) use ($ctx) {
            $ids = $ctx['candidates'][$i];
            if ($prev !== null && in_array($prev, $ids, true)) {
                $ids = array_values(array_unique(array_merge([$prev], $ids)));
            }

            return $ids;
        });
    }

    protected function place(array $ctx, array $day, int $t, \Closure $candidatesFor): ?Slot
    {
        $cursor = $t;
        $items = [];
        $prev = null;
        foreach ($ctx['lines'] as $i => $line) {
            $placed = null;
            foreach ($candidatesFor($i, $prev) as $staffId) {
                if (! isset($day['free'][$staffId])) {
                    continue;
                }
                $staff = $ctx['staff'][$staffId];
                $duration = $ctx['durations'][$i][$staffId] ??= $line->durationFor($ctx['branch'], $staff);
                $bb = (int) $line->service->buffer_before;
                $ba = (int) $line->service->buffer_after;
                $start = $cursor;
                $end = $cursor + $duration * 60;
                if (! $this->contains($day['free'][$staffId], $start - $bb * 60, $end + $ba * 60)) {
                    continue;
                }
                $resources = $this->pickResources($ctx, $line, $start - $bb * 60, $end + $ba * 60);
                if ($resources === null) {
                    continue;
                }
                $placed = [
                    'line' => $i,
                    'service_id' => (int) $line->service->id,
                    'variant_id' => $line->variant?->id,
                    'addon_ids' => $line->addons->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                    'staff_id' => (int) $staffId,
                    'resource_id' => $resources[0] ?? null,
                    'resource_ids' => $resources,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'buffer_before' => $bb,
                    'buffer_after' => $ba,
                    'price' => $ctx['prices'][$i][$staffId] ??= $line->priceFor($ctx['branch'], $staff),
                    'duration_minutes' => $duration,
                ];
                break;
            }
            if (! $placed) {
                return null;
            }
            $items[] = $placed;
            $prev = $placed['staff_id'];
            $cursor = $placed['ends_at'];
        }

        return new Slot($t, $cursor, $items);
    }

    /** Enough free units of every required resource type? Returns the chosen resource ids (one per type). */
    protected function pickResources(array $ctx, ServiceLine $line, int $from, int $to): ?array
    {
        $chosen = [];
        foreach ($line->resourceTypes() as $type) {
            $capacity = $ctx['capacity'][$type] ?? 0;
            if ($capacity <= 0) {
                return null;
            }
            $usedIds = [];
            $count = 0;
            foreach ($ctx['resourceUsage'][$type] ?? [] as [$s, $e, $rid]) {
                if ($s < $to && $e > $from) {
                    $count++;
                    if ($rid) {
                        $usedIds[$rid] = true;
                    }
                }
            }
            if ($count >= $capacity) {
                return null;
            }
            $pick = null;
            foreach ($ctx['resources'][$type] as $rid) {
                if (! isset($usedIds[$rid])) {
                    $pick = $rid;
                    break;
                }
            }
            $chosen[] = $pick ?? $ctx['resources'][$type][0];
        }

        return $chosen;
    }

    /**
     * Load everything needed for [from, to] (local dates) in a handful of queries.
     */
    protected function context(Branch $branch, array $lines, CarbonImmutable $from, CarbonImmutable $to, array $options): array
    {
        $tz = $this->tz($branch);
        $tenantId = (int) $branch->tenant_id;
        $windowStart = $from->subDay()->utc()->format('Y-m-d H:i:s');
        $windowEnd = $to->addDays(2)->utc()->format('Y-m-d H:i:s');
        $online = $options['online'] ?? true;
        $now = $this->now($options);

        $serviceIds = array_values(array_unique(array_map(fn (ServiceLine $l) => (int) $l->service->id, $lines)));
        foreach ($lines as $line) {
            $line->service->loadMissing('branches');
        }

        $staff = Staff::query()->withoutGlobalScope('branch_visibility')
            ->where('is_active', true)
            ->when($online, fn ($q) => $q->where('is_bookable', true))
            ->whereHas('branches', fn ($q) => $q->withoutGlobalScopes()->where('branches.id', $branch->id))
            ->whereHas('services', fn ($q) => $q->whereIn('services.id', $serviceIds))
            ->with(['services' => fn ($q) => $q->whereIn('services.id', $serviceIds)])
            ->orderBy('sort')->orderBy('id')
            ->get()->keyBy('id');

        $candidates = [];
        foreach ($lines as $i => $line) {
            $ids = [];
            foreach ($staff as $id => $s) {
                if ($s->services->contains('id', $line->service->id) && ($line->staffId === null || $line->staffId === (int) $id)) {
                    $ids[] = (int) $id;
                }
            }
            $candidates[$i] = $ids;
        }
        $staffIds = $staff->keys()->map(fn ($id) => (int) $id)->all();

        // Schedules (this branch) + whether a staff member has any schedule at all.
        $schedules = [];
        $hasSchedules = array_fill_keys($staffIds, false);
        if ($staffIds) {
            foreach (DB::table('staff_schedules')->whereIn('staff_id', $staffIds)->select('staff_id')->distinct()->pluck('staff_id') as $sid) {
                $hasSchedules[(int) $sid] = true;
            }
            StaffSchedule::query()->where('branch_id', $branch->id)->whereIn('staff_id', $staffIds)->get()
                ->each(function ($row) use (&$schedules) {
                    $schedules[(int) $row->staff_id][(int) $row->weekday][] = [
                        'starts_at' => substr((string) $row->starts_at, 0, 5),
                        'ends_at' => substr((string) $row->ends_at, 0, 5),
                        'breaks' => array_values(array_filter((array) ($row->breaks ?? []), fn ($b) => is_array($b) && count($b) === 2)),
                    ];
                });
        }

        // Busy staff intervals: approved time off, blocking bookings (with buffers), active holds.
        $busy = [];
        if ($staffIds) {
            TimeOff::query()->approved()->whereIn('staff_id', $staffIds)
                ->where('starts_at', '<', $windowEnd)->where('ends_at', '>', $windowStart)->get()
                ->each(function ($off) use (&$busy) {
                    $busy[(int) $off->staff_id][] = [$off->starts_at->getTimestamp(), $off->ends_at->getTimestamp()];
                });
        }

        $resourceRows = Resource::query()->where('branch_id', $branch->id)->where('is_active', true)->orderBy('id')->get();
        $resources = [];
        $capacity = [];
        $resourceType = [];
        foreach ($resourceRows as $r) {
            $resources[$r->type][] = (int) $r->id;
            $capacity[$r->type] = ($capacity[$r->type] ?? 0) + max(1, (int) $r->capacity);
            $resourceType[(int) $r->id] = $r->type;
        }

        $items = DB::table('booking_items as i')->join('bookings as b', 'b.id', '=', 'i.booking_id')
            ->where('b.tenant_id', $tenantId)->whereNull('b.deleted_at')
            ->whereIn('b.status', array_map(fn ($s) => $s->value, BookingStatus::blocking()))
            ->where(fn ($q) => $q->where('b.branch_id', $branch->id)->when($staffIds, fn ($q) => $q->orWhereIn('i.staff_id', $staffIds)))
            ->where('i.starts_at', '<', $windowEnd)->where('i.ends_at', '>', $windowStart)
            ->when($options['ignore_booking_id'] ?? null, fn ($q, $id) => $q->where('b.id', '!=', $id))
            ->select('i.staff_id', 'i.resource_id', 'i.service_id', 'i.starts_at', 'i.ends_at', 'i.buffer_before', 'i.buffer_after', 'b.branch_id')
            ->get();

        $serviceTypes = [];
        if ($items->isNotEmpty()) {
            foreach (DB::table('services')->whereIn('id', $items->pluck('service_id')->unique())->pluck('resource_types', 'id') as $id => $json) {
                $serviceTypes[(int) $id] = array_values(array_filter((array) (is_string($json) ? json_decode($json, true) : $json)));
            }
        }

        $resourceUsage = [];
        foreach ($items as $it) {
            $s = strtotime($it->starts_at.' UTC') - (int) $it->buffer_before * 60;
            $e = strtotime($it->ends_at.' UTC') + (int) $it->buffer_after * 60;
            if ($it->staff_id) {
                $busy[(int) $it->staff_id][] = [$s, $e];
            }
            if ((int) $it->branch_id === (int) $branch->id) {
                foreach ($serviceTypes[(int) $it->service_id] ?? [] as $type) {
                    $rid = $it->resource_id && ($resourceType[(int) $it->resource_id] ?? null) === $type ? (int) $it->resource_id : null;
                    $resourceUsage[$type][] = [$s, $e, $rid];
                }
            }
        }

        DB::table('slot_holds')->where('tenant_id', $tenantId)->whereNull('booking_id')
            ->where('expires_at', '>', $now->utc()->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->where('branch_id', $branch->id)->when($staffIds, fn ($q) => $q->orWhereIn('staff_id', $staffIds)))
            ->where('starts_at', '<', $windowEnd)->where('ends_at', '>', $windowStart)
            ->when($options['ignore_hold_token'] ?? null, fn ($q, $token) => $q->where('token', 'not like', $token.'.%'))
            ->get(['staff_id', 'resource_id', 'starts_at', 'ends_at'])
            ->each(function ($h) use (&$busy, &$resourceUsage, $resourceType) {
                $s = strtotime($h->starts_at.' UTC');
                $e = strtotime($h->ends_at.' UTC');
                if ($h->staff_id) {
                    $busy[(int) $h->staff_id][] = [$s, $e];
                }
                if ($h->resource_id && isset($resourceType[(int) $h->resource_id])) {
                    $resourceUsage[$resourceType[(int) $h->resource_id]][] = [$s, $e, (int) $h->resource_id];
                }
            });

        return [
            'branch' => $branch, 'tz' => $tz, 'lines' => array_values($lines), 'staff' => $staff->all(), 'candidates' => $candidates,
            'schedules' => $schedules, 'hasSchedules' => $hasSchedules, 'busy' => $busy, 'resources' => $resources,
            'capacity' => $capacity, 'resourceUsage' => $resourceUsage, 'durations' => [], 'prices' => [],
        ];
    }

    // --- interval helpers --------------------------------------------------------------------

    /** @param list<array{0:int,1:int}> $windows */
    protected function subtract(array $windows, array $busy): array
    {
        usort($busy, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($windows as [$ws, $we]) {
            $cursor = $ws;
            foreach ($busy as [$bs, $be]) {
                if ($be <= $cursor || $bs >= $we) {
                    continue;
                }
                if ($bs > $cursor) {
                    $out[] = [$cursor, $bs];
                }
                $cursor = max($cursor, $be);
                if ($cursor >= $we) {
                    break;
                }
            }
            if ($cursor < $we) {
                $out[] = [$cursor, $we];
            }
        }

        return $out;
    }

    protected function contains(array $free, int $from, int $to): bool
    {
        foreach ($free as [$s, $e]) {
            if ($from >= $s && $to <= $e) {
                return true;
            }
        }

        return false;
    }

    // --- time helpers ------------------------------------------------------------------------

    protected function ts(string $date, string $hm, string $tz): int
    {
        return CarbonImmutable::parse($date.' '.substr($hm, 0, 5), $tz)->getTimestamp();
    }

    public function tz(Branch $branch): string
    {
        return $branch->timezone ?: (tenant()?->timezone ?? config('salon.defaults.timezone', 'UTC'));
    }

    protected function now(array $options): CarbonImmutable
    {
        return CarbonImmutable::instance($options['now'] ?? now());
    }

    protected function today(Branch $branch, array $options): CarbonImmutable
    {
        return $this->now($options)->setTimezone($this->tz($branch))->startOfDay();
    }

    protected function localDate(Branch $branch, CarbonInterface|string $date): CarbonImmutable
    {
        $tz = $this->tz($branch);

        return $date instanceof CarbonInterface
            ? CarbonImmutable::parse($date->format('Y-m-d'), $tz)
            : CarbonImmutable::parse(substr($date, 0, 10), $tz);
    }

    protected function dateAllowed(Branch $branch, CarbonImmutable $date, array $options): bool
    {
        $today = $this->today($branch, $options);
        if ($date->lt($today)) {
            return false;
        }

        return ! ($options['enforce_max_days'] ?? true) || $date->lte($today->addDays(BookingSettings::get('max_days_ahead')));
    }

    /** Helper for callers holding raw ids. @return Collection<int, Staff> */
    public function capableStaff(Branch $branch, array $serviceIds, bool $online = true): Collection
    {
        return Staff::query()->withoutGlobalScope('branch_visibility')->where('is_active', true)
            ->when($online, fn ($q) => $q->where('is_bookable', true))
            ->whereHas('branches', fn ($q) => $q->withoutGlobalScopes()->where('branches.id', $branch->id))
            ->when($serviceIds, fn ($q) => $q->whereHas('services', fn ($s) => $s->whereIn('services.id', $serviceIds)))
            ->orderBy('sort')->orderBy('id')->get();
    }
}
