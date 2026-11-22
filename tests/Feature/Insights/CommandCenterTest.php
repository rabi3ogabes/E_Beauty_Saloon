<?php

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Insights\CommandCenter\TodaySnapshot;
use App\Domain\Insights\Support\InsightSettings;
use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Team\Models\TimeOff;
use App\Filament\Dashboard\Widgets\Insights\BranchPulse;
use App\Filament\Dashboard\Widgets\Insights\CommandCenterKpis;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

require_once __DIR__.'/InsightsKit.php';

beforeEach(function () {
    // Wednesday 18 Nov 2026, 12:00 in Doha.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00', 'Asia/Qatar'));
});

it('computes today per branch: bookings, utilization, revenue vs target, staff on shift and low stock', function () {
    $s = insightsSalon();
    workSchedule($s, $s->staff[0], '10:00', '20:00'); // Layla 600 min; Sofia has no schedule → branch hours 10–22 = 720 min
    insightsBooking($s, '2026-11-18 10:00', ['cut'], $s->staff[0]);
    insightsBooking($s, '2026-11-18 11:00', ['color'], $s->staff[1], BookingStatus::Confirmed);
    insightsBooking($s, '2026-11-18 15:00', ['cut'], $s->staff[1], BookingStatus::CancelledByClient);
    insightsSale($s, '2026-11-18 10:30', [['service' => 'cut', 'staff' => 0, 'total' => 20000]], tips: 1000);
    asTenant($s->tenant, function () use ($s) {
        InsightSettings::put(['targets' => ['daily' => [$s->branch->id => 40000]]]);
        app(StockLedger::class)->move($s->branch->id, $s->variant, 2, 'adjustment'); // 2 ≤ default threshold 3 → low
    });

    $rows = asTenant($s->tenant, fn () => collect(app(TodaySnapshot::class)->branches())->keyBy('branch_id'));
    $main = $rows[$s->branch->id];

    expect($main['bookings'])->toBe(2)
        ->and($main['utilization'])->toBe(round(150 / 1320 * 100, 1))
        ->and($main['revenue'])->toBe(20000)
        ->and($main['target'])->toBe(40000)
        ->and($main['target_progress'])->toBe(50.0)
        ->and($main['staff_on_shift'])->toBe(2)
        ->and($main['low_stock'])->toBe(1)
        ->and($rows[$s->second->id]['bookings'])->toBe(0);

    // Approved time off takes Sofia off the floor.
    asTenant($s->tenant, fn () => TimeOff::create(['staff_id' => $s->staff[1]->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3), 'status' => 'approved']));
    expect(asTenant($s->tenant, fn () => app(TodaySnapshot::class)->branch($s->branch->fresh())['staff_on_shift']))->toBe(1);
});

it('respects branch restrictions and the branch switcher', function () {
    $s = insightsSalon();
    insightsBooking($s, '2026-11-18 10:00', ['cut']);
    insightsBooking($s, '2026-11-18 13:00', ['cut'], branch: $s->second);
    insightsBooking($s, '2026-11-18 14:00', ['blowdry'], branch: $s->second);

    $restricted = asTenant($s->tenant, function () use ($s) {
        app(TenantContext::class)->restrictToBranches([$s->second->id]);

        return app(TodaySnapshot::class)->totals();
    });
    expect($restricted['bookings'])->toBe(2);

    $switched = asTenant($s->tenant, function () use ($s) {
        app(TenantContext::class)->setBranch($s->branch->id);

        return [app(TodaySnapshot::class)->branches(), app(TodaySnapshot::class)->totals()];
    });
    expect($switched[0])->toHaveCount(1)->and($switched[0][0]['branch_id'])->toBe($s->branch->id)->and($switched[1]['bookings'])->toBe(1);
});

it('renders the command center widgets for the owner and hides them from stylists', function () {
    $s = insightsSalon();
    $s->tenant->forceFill(['onboarded_at' => now()])->save();
    insightsBooking($s, '2026-11-18 10:00', ['cut']);

    $this->actingAs($s->tenant->owner)->get(tenantUrl($s->tenant, 'dashboard'))
        ->assertOk()->assertSee(__('insights.pulse.title'))->assertSee(__('insights.kpi.utilization'))->assertSee('Pearl');

    $stylist = tenantUser($s->tenant, 'stylist', [$s->branch->id]);
    $this->actingAs($stylist);
    asTenant($s->tenant, fn () => expect(BranchPulse::canView())->toBeFalse()->and(CommandCenterKpis::canView())->toBeFalse());
});
