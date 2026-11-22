<?php

use App\Domain\Booking\Availability\AvailabilityCache;
use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Resource;
use App\Domain\Team\Models\TimeOff;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Helpers.php';

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-30 09:00', 'Asia/Qatar')));

it('answers a multi-service availability query in under 300ms on a busy salon', function () {
    $salon = bookingSalon(['staff' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H']]);
    asTenant($salon->tenant, function () use ($salon) {
        foreach (['chair', 'chair', 'chair', 'chair', 'chair', 'chair', 'chair', 'chair', 'room', 'wash_basin', 'wash_basin'] as $i => $type) {
            Resource::create(['branch_id' => $salon->branch->id, 'name' => $type.$i, 'type' => $type]);
        }
        $salon->services['cut']->update(['resource_types' => ['chair'], 'buffer_after' => 10]);
        $salon->services['color']->update(['resource_types' => ['chair', 'wash_basin'], 'buffer_after' => 15]);
    });
    foreach ($salon->staff as $s) {
        workSchedule($salon, $s, '10:00', '21:00', [['14:00', '15:00']]);
    }
    // ~240 bookings over the next 14 days + some time off.
    asTenant($salon->tenant, function () use ($salon) {
        mt_srand(42);
        for ($d = 1; $d <= 14; $d++) {
            foreach ($salon->staff as $s) {
                foreach ([10, 12, 16, 18] as $h) {
                    if (mt_rand(0, 9) < 5) {
                        $start = atLocal($salon, CarbonImmutable::parse('2026-11-30')->addDays($d)->format('Y-m-d')." {$h}:00");
                        $b = Booking::create(['branch_id' => $salon->branch->id, 'status' => 'confirmed', 'starts_at' => $start, 'ends_at' => $start->addMinutes(60)]);
                        $b->items()->create(['service_id' => $salon->services['cut']->id, 'staff_id' => $s->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(60), 'buffer_after' => 10, 'price' => 1, 'duration_minutes' => 60]);
                    }
                }
            }
        }
        TimeOff::create(['staff_id' => $salon->staff[0]->id, 'starts_at' => atLocal($salon, '2026-12-03 10:00'), 'ends_at' => atLocal($salon, '2026-12-05 22:00'), 'status' => 'approved']);
    });

    $lines = lines($salon, ['cut', 'color', 'blowdry']);
    asTenant($salon->tenant, fn () => engine()->day($salon->branch, $lines, '2026-12-02', ['cache' => false])); // warm-up (autoload, query plans)

    $times = [];
    foreach (['2026-12-03', '2026-12-04', '2026-12-05', '2026-12-06'] as $date) {
        $t = hrtime(true);
        $day = asTenant($salon->tenant, fn () => engine()->day($salon->branch, $lines, $date, ['cache' => false]));
        $times[] = (hrtime(true) - $t) / 1e6;
        expect($day->slots)->not->toBeEmpty();
    }
    expect(max($times))->toBeLessThan(300);

    // Cached reads are near-instant.
    asTenant($salon->tenant, fn () => engine()->day($salon->branch, $lines, '2026-12-07'));
    $t = hrtime(true);
    asTenant($salon->tenant, fn () => engine()->day($salon->branch, $lines, '2026-12-07'));
    expect((hrtime(true) - $t) / 1e6)->toBeLessThan(50);
});

it('invalidates cached availability when bookings, schedules or time off change', function () {
    $salon = bookingSalon(['staff' => ['Layla']]);
    $cache = app(AvailabilityCache::class);
    $v0 = $cache->version($salon->tenant->id, $salon->branch->id);
    expect(slotTimes($salon, ['cut'], '2026-12-01'))->toContain('11:00');

    asTenant($salon->tenant, function () use ($salon) {
        $b = Booking::create(['branch_id' => $salon->branch->id, 'status' => 'confirmed', 'starts_at' => atLocal($salon, '2026-12-01 11:00'), 'ends_at' => atLocal($salon, '2026-12-01 12:00')]);
        $b->items()->create(['service_id' => $salon->services['cut']->id, 'staff_id' => $salon->staff[0]->id, 'starts_at' => $b->starts_at, 'ends_at' => $b->ends_at, 'price' => 1, 'duration_minutes' => 60]);
    });
    $v1 = $cache->version($salon->tenant->id, $salon->branch->id);
    expect($v1)->not->toBe($v0)->and(slotTimes($salon, ['cut'], '2026-12-01'))->not->toContain('11:00');

    workSchedule($salon, $salon->staff[0], '15:00', '20:00');
    expect($cache->version($salon->tenant->id, $salon->branch->id))->not->toBe($v1)
        ->and(slotTimes($salon, ['cut'], '2026-12-01')[0])->toBe('15:00');

    asTenant($salon->tenant, fn () => TimeOff::create(['staff_id' => $salon->staff[0]->id, 'starts_at' => atLocal($salon, '2026-12-01 15:00'), 'ends_at' => atLocal($salon, '2026-12-01 17:00'), 'status' => 'approved']));
    expect(slotTimes($salon, ['cut'], '2026-12-01')[0])->toBe('17:00');
});
