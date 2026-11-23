<?php

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SlotHold;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchHoliday;
use App\Domain\Branches\Models\Resource;
use App\Domain\Team\Models\TimeOff;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Helpers.php';

// "Now" = Monday 30 Nov 2026 09:00 in Doha; tests look at Tuesday 1 Dec 2026 (weekday 2).
beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-30 09:00', 'Asia/Qatar')));

const DAY = '2026-12-01';

function occupy(object $salon, $staff, string $from, string $to, array $extra = []): Booking
{
    return asTenant($salon->tenant, function () use ($salon, $staff, $from, $to, $extra) {
        $b = Booking::create(['branch_id' => $salon->branch->id, 'status' => 'confirmed', 'starts_at' => atLocal($salon, $from), 'ends_at' => atLocal($salon, $to)]);
        $b->items()->create(array_merge([
            'service_id' => $salon->services['cut']->id, 'staff_id' => $staff?->id, 'starts_at' => atLocal($salon, $from), 'ends_at' => atLocal($salon, $to),
            'price' => 1000, 'duration_minutes' => 60,
        ], $extra));

        return $b;
    });
}

it('follows branch opening hours in the branch timezone', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $times = slotTimes($salon, ['cut'], DAY);

    expect($times[0])->toBe('10:00')->and(end($times))->toBe('21:00')->and($times)->toHaveCount(45);
});

it('stores slots in UTC while showing branch-local times (Asia/Qatar vs UTC app)', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $day = asTenant($salon->tenant, fn () => engine()->day($salon->branch, lines($salon, ['cut']), DAY));

    expect(config('app.timezone'))->toBe('UTC')
        ->and($day->first()->startsAt()->toIso8601ZuluString())->toBe('2026-12-01T07:00:00Z')
        ->and($day->toArray()['periods']['morning'][0]['time'])->toBe('10:00');
});

it('honours holidays (closed and partial days)', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    asTenant($salon->tenant, fn () => BranchHoliday::create(['branch_id' => $salon->branch->id, 'date' => DAY, 'name' => 'National day']));
    expect(slotTimes($salon, ['cut'], DAY))->toBe([]);

    asTenant($salon->tenant, fn () => BranchHoliday::create(['branch_id' => $salon->branch->id, 'date' => '2026-12-02', 'opens_at' => '12:00', 'closes_at' => '16:00']));
    $times = slotTimes($salon, ['cut'], '2026-12-02');
    expect($times[0])->toBe('12:00')->and(end($times))->toBe('15:00');
});

it('uses staff schedules and breaks', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    workSchedule($salon, $salon->staff[0], '10:00', '18:00', [['13:00', '14:00']]);
    $times = slotTimes($salon, ['cut'], DAY);

    expect($times)->toContain('12:00')->not->toContain('12:15')->not->toContain('13:30')
        ->toContain('14:00')->and(end($times))->toBe('17:00');

    // Not scheduled on that weekday = not available.
    $salon2 = bookingSalon(['staff' => ['Hana']]);
    workSchedule($salon2, $salon2->staff[0], weekdays: [0, 1]);
    expect(slotTimes($salon2, ['cut'], DAY))->toBe([]);
});

it('blocks approved time off only', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    asTenant($salon->tenant, function () use ($salon) {
        TimeOff::create(['staff_id' => $salon->staff[0]->id, 'starts_at' => atLocal($salon, DAY.' 10:00'), 'ends_at' => atLocal($salon, DAY.' 15:00'), 'status' => 'approved']);
        TimeOff::create(['staff_id' => $salon->staff[0]->id, 'starts_at' => atLocal($salon, DAY.' 15:00'), 'ends_at' => atLocal($salon, DAY.' 22:00'), 'status' => 'pending']);
    });

    expect(slotTimes($salon, ['cut'], DAY)[0])->toBe('15:00');
});

it('respects existing bookings with their buffers and the service buffers', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    occupy($salon, $salon->staff[0], DAY.' 12:00', DAY.' 13:00', ['buffer_after' => 15]);
    $times = slotTimes($salon, ['cut'], DAY);
    expect($times)->toContain('11:00')->not->toContain('11:15')->not->toContain('13:00')->toContain('13:15');

    asTenant($salon->tenant, fn () => $salon->services['cut']->update(['buffer_before' => 15, 'buffer_after' => 15]));
    $times = slotTimes($salon, ['cut'], DAY);
    expect($times[0])->toBe('10:15')            // prep time inside opening hours
        ->and($times)->toContain('10:45')       // 10:45–11:45 + 15 min clean-up ends exactly at 12:00
        ->and($times)->not->toContain('11:00') // would overlap the 12:00 booking
        ->and($times)->toContain('13:30');     // 13:00 + 15 min clean-up + 15 min prep
});

it('ignores cancelled bookings', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $b = occupy($salon, $salon->staff[0], DAY.' 10:00', DAY.' 21:30');
    expect(slotTimes($salon, ['cut'], DAY))->toBe([]);

    asTenant($salon->tenant, fn () => $b->update(['status' => 'cancelled_by_client']));
    expect(slotTimes($salon, ['cut'], DAY))->toHaveCount(45);
});

it('requires enough free resources of each type', function () {
    $salon = bookingSalon();
    asTenant($salon->tenant, fn () => $salon->services['color']->update(['resource_types' => ['room']]));
    expect(slotTimes($salon, ['color'], DAY))->toBe([]); // no room at this branch

    $room = asTenant($salon->tenant, fn () => Resource::create(['branch_id' => $salon->branch->id, 'name' => 'Room', 'type' => 'room']));
    occupy($salon, $salon->staff[0], DAY.' 12:00', DAY.' 13:30', ['service_id' => $salon->services['color']->id, 'resource_id' => $room->id]);

    // Sofia is free at 12:00 but the only room is taken.
    $times = slotTimes($salon, ['color'], DAY);
    expect($times)->toContain('10:30')->not->toContain('11:00')->not->toContain('12:00')->toContain('13:30');

    $day = asTenant($salon->tenant, fn () => engine()->day($salon->branch, lines($salon, ['color']), DAY));
    expect($day->first()->items[0]['resource_id'])->toBe($room->id);
});

it('applies minimum notice, max days ahead and the slot interval', function () {
    $salon = bookingSalon(['staff' => ['Layla'], 'settings' => ['min_notice_minutes' => 60, 'max_days_ahead' => 10, 'slot_interval' => 30]]);
    $this->travelTo(CarbonImmutable::parse(DAY.' 10:20', 'Asia/Qatar'));

    $times = slotTimes($salon, ['cut'], DAY);
    expect($times[0])->toBe('11:30')->and($times[1])->toBe('12:00');
    expect(slotTimes($salon, ['cut'], '2026-12-11'))->not->toBe([])
        ->and(slotTimes($salon, ['cut'], '2026-12-12'))->toBe([])
        ->and(slotTimes($salon, ['cut'], '2026-11-30'))->toBe([]);
});

it('chains multiple services back-to-back with the same staff and sums time and price', function () {
    $salon = bookingSalon();
    $day = asTenant($salon->tenant, fn () => engine()->day($salon->branch, lines($salon, ['cut', 'blowdry']), DAY));
    $slot = $day->first();

    expect($slot->durationMinutes())->toBe(90)->and($slot->price())->toBe(30000)
        ->and($slot->staffIds())->toBe([$salon->staff[0]->id])
        ->and($slot->items[1]['starts_at'])->toBe($slot->items[0]['ends_at']);
});

it('chains services across different staff when nobody can do all of them', function () {
    $salon = bookingSalon();
    asTenant($salon->tenant, function () use ($salon) {
        $salon->staff[0]->services()->detach($salon->services['blowdry']->id);
        $salon->staff[1]->services()->detach($salon->services['cut']->id);
    });

    $slot = asTenant($salon->tenant, fn () => engine()->day($salon->branch, lines($salon, ['cut', 'blowdry']), DAY)->first());
    expect($slot->items[0]['staff_id'])->toBe($salon->staff[0]->id)
        ->and($slot->items[1]['staff_id'])->toBe($salon->staff[1]->id)
        ->and($slot->durationMinutes())->toBe(90);
});

it('honours a preferred staff member and staff price/duration overrides', function () {
    $salon = bookingSalon();
    asTenant($salon->tenant, fn () => $salon->staff[1]->services()->updateExistingPivot($salon->services['cut']->id, ['price' => 30000, 'duration_minutes' => 45]));

    $slot = asTenant($salon->tenant, fn () => engine()->day($salon->branch, lines($salon, [['service' => 'cut', 'staff_id' => $salon->staff[1]->id]]), DAY)->first());
    expect($slot->staffIds())->toBe([$salon->staff[1]->id])->and($slot->price())->toBe(30000)->and($slot->durationMinutes())->toBe(45);
});

it('adds variant and add-on duration and price', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    [$variant, $addon] = asTenant($salon->tenant, fn () => [
        $salon->services['cut']->variants()->create(['tenant_id' => $salon->tenant->id, 'name' => ['en' => 'Long'], 'duration_minutes' => 75, 'price' => 25000]),
        $salon->services['cut']->addons()->create(['tenant_id' => $salon->tenant->id, 'name' => ['en' => 'Mask'], 'duration_minutes' => 15, 'price' => 5000]),
    ]);
    $slot = asTenant($salon->tenant, fn () => engine()->day($salon->branch, lines($salon, [['service' => 'cut', 'variant_id' => $variant->id, 'addon_ids' => [$addon->id]]]), DAY)->first());

    expect($slot->durationMinutes())->toBe(90)->and($slot->price())->toBe(30000);
});

it('returns the next available date when a day has no slots', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    asTenant($salon->tenant, function () use ($salon) {
        BranchHoliday::create(['branch_id' => $salon->branch->id, 'date' => DAY]);
        BranchHoliday::create(['branch_id' => $salon->branch->id, 'date' => '2026-12-02']);
    });
    $day = asTenant($salon->tenant, fn () => engine()->dayWithNext($salon->branch, lines($salon, ['cut']), DAY));

    expect($day->closed)->toBeTrue()->and($day->nextAvailableDate)->toBe('2026-12-03');
});

it('treats active slot holds as busy unless they are your own', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    asTenant($salon->tenant, fn () => SlotHold::create(['branch_id' => $salon->branch->id, 'staff_id' => $salon->staff[0]->id, 'starts_at' => atLocal($salon, DAY.' 10:00'),
        'ends_at' => atLocal($salon, DAY.' 12:00'), 'token' => 'abc.0', 'expires_at' => now()->addMinutes(10)]));

    expect(slotTimes($salon, ['cut'], DAY)[0])->toBe('12:00')
        ->and(slotTimes($salon, ['cut'], DAY, ['ignore_hold_token' => 'abc'])[0])->toBe('10:00');

    $this->travel(11)->minutes(); // expired holds no longer block
    expect(slotTimes($salon, ['cut'], DAY, ['cache' => false])[0])->toBe('10:00');
});

it('sees a staff member busy at another branch', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $other = asTenant($salon->tenant, fn () => tap(Branch::create(['name' => ['en' => 'Pearl'], 'slug' => 'pearl', 'opening_hours' => Branch::defaultHours()]),
        fn ($b) => $salon->staff[0]->branches()->attach($b->id)));
    asTenant($salon->tenant, function () use ($salon, $other) {
        $b = Booking::create(['branch_id' => $other->id, 'status' => 'confirmed', 'starts_at' => atLocal($salon, DAY.' 10:00'), 'ends_at' => atLocal($salon, DAY.' 14:00')]);
        $b->items()->create(['service_id' => $salon->services['cut']->id, 'staff_id' => $salon->staff[0]->id, 'starts_at' => $b->starts_at, 'ends_at' => $b->ends_at, 'price' => 1, 'duration_minutes' => 240]);
    });

    expect(slotTimes($salon, ['cut'], DAY)[0])->toBe('14:00');
});

it('quotes an exact start time and refuses taken ones', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    occupy($salon, $salon->staff[0], DAY.' 12:00', DAY.' 13:00');

    asTenant($salon->tenant, function () use ($salon) {
        expect(engine()->quote($salon->branch, lines($salon, ['cut']), atLocal($salon, DAY.' 10:05')))->not->toBeNull()
            ->and(engine()->quote($salon->branch, lines($salon, ['cut']), atLocal($salon, DAY.' 11:30')))->toBeNull();
    });
});
