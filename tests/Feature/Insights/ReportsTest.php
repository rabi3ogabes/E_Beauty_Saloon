<?php

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Insights\Actions\SendMorningReports;
use App\Domain\Insights\Models\ReportDelivery;
use App\Domain\Insights\Reports\ReportService;
use App\Domain\Insights\Support\InsightSettings;
use App\Domain\Insights\Support\Period;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Shop\Models\Order;
use App\Filament\Dashboard\Pages\Reports\RevenueReport;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

require_once __DIR__.'/InsightsKit.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00', 'Asia/Qatar'));
});

function reportsFixture(): object
{
    $s = insightsSalon();
    insightsSale($s, '2026-11-10 14:00', [['service' => 'cut', 'staff' => 0, 'total' => 20000], ['product' => true, 'qty' => 2, 'total' => 30000]], tips: 1000);
    insightsSale($s, '2026-11-11 11:00', [['service' => 'color', 'staff' => 1, 'total' => 40000], ['service' => 'blowdry', 'staff' => 1, 'total' => 10000]], refunded: 10000, refundedQty: [1 => 1]);
    insightsSale($s, '2026-11-12 16:00', [['service' => 'cut', 'staff' => 0, 'total' => 20000]], branch: $s->second);
    insightsSale($s, '2026-10-20 12:00', [['service' => 'cut', 'staff' => 0, 'total' => 20000]]); // previous month
    asTenant($s->tenant, function () use ($s) {
        $order = Order::create([
            'branch_id' => $s->branch->id, 'sequence' => 10001, 'number' => 'SO-10001', 'public_token' => Str::random(32), 'status' => 'delivered',
            'customer_name' => 'Guest', 'customer_phone' => '+97455500000', 'subtotal' => 15000, 'total' => 15000,
        ]);
        $order->forceFill(['created_at' => CarbonImmutable::parse('2026-11-13 18:00', 'Asia/Qatar')->utc()])->save();
        $order->items()->create(['product_id' => $s->product->id, 'product_variant_id' => $s->variant->id, 'name' => ['en' => 'Silk Oil'], 'qty' => 1, 'unit_price' => 15000, 'total' => 15000]);
    });

    return $s;
}

it('reports revenue by branch, staff, service and product on controlled data', function () {
    $s = reportsFixture();
    // Noise in another salon must never leak in.
    $other = insightsSalon();
    insightsSale($other, '2026-11-10 14:00', [['service' => 'cut', 'staff' => 0, 'total' => 99900]]);

    asTenant($s->tenant, function () use ($s) {
        $r = app(ReportService::class);
        $p = Period::preset('this_month');

        expect($r->revenueTotal($p))->toBe(125000)
            ->and($r->revenueTotal($p, ['channel' => 'pos']))->toBe(110000)
            ->and($r->revenueTotal($p, ['branch_id' => $s->second->id]))->toBe(20000)
            ->and($r->revenueTotal($p, ['service_category_id' => $s->category->id]))->toBe(80000)
            ->and($r->revenueTotal($p, ['service_category_id' => $s->category->id, 'branch_id' => $s->second->id]))->toBe(20000)
            ->and($r->revenueTotal(Period::preset('last_month')))->toBe(20000);

        $branches = collect($r->revenueByBranch($p))->keyBy('branch_id');
        expect($branches[$s->branch->id])->toMatchArray(['pos' => 90000, 'shop' => 15000, 'total' => 105000, 'tickets' => 2, 'average_ticket' => 45000])
            ->and($branches[$s->second->id]['total'])->toBe(20000);

        $staff = collect($r->revenueByStaff($p))->keyBy('staff');
        expect($staff['Layla']['total'])->toBe(40000)->and($staff['Sofia']['total'])->toBe(40000)->and($staff['Sofia']['items'])->toBe(1);

        $services = collect($r->revenueByService($p))->keyBy('service');
        expect($services['Cut'])->toMatchArray(['count' => 2, 'total' => 40000])
            ->and($services['Color']['total'])->toBe(40000)
            ->and($services['Blow dry']['total'])->toBe(0);

        $products = collect($r->revenueByProduct($p))->keyBy('product_id');
        expect($products[$s->product->id])->toMatchArray(['units' => 3, 'pos' => 30000, 'shop' => 15000, 'total' => 45000]);

        expect($r->averageTicket($p))->toMatchArray(['tickets' => 3, 'revenue' => 110000, 'average' => 36666]);
        expect(array_sum($r->revenueDaily($p)))->toBe(125000)->and($r->revenueDaily($p)['2026-11-13'])->toBe(15000);
    });
});

it('computes no-show, rebook, utilization heatmap and retention cohorts', function () {
    $s = insightsSalon();
    $x = insightsClient($s, '+97455501001', 'Xena');
    $y = insightsClient($s, '+97455501002', 'Yara');
    insightsBooking($s, '2026-10-20 10:00', ['cut'], client: $x);
    insightsBooking($s, '2026-11-02 10:00', ['cut'], client: $x);
    insightsBooking($s, '2026-11-09 10:00', ['cut'], client: $x);
    insightsBooking($s, '2026-11-05 10:00', ['cut'], client: $y);
    insightsBooking($s, '2026-11-06 10:00', ['cut'], client: $y, status: BookingStatus::NoShow);

    // Monday 16 Nov: Layla 10–14 (240 min), Sofia 10–12 (120 min); 60 + 30 minutes booked.
    workSchedule($s, $s->staff[0], '10:00', '14:00', weekdays: [1]);
    workSchedule($s, $s->staff[1], '10:00', '12:00', weekdays: [1]);
    insightsBooking($s, '2026-11-16 10:00', ['cut'], $s->staff[0], BookingStatus::Confirmed);
    insightsBooking($s, '2026-11-16 11:00', ['blowdry'], $s->staff[1], BookingStatus::Confirmed);

    asTenant($s->tenant, function () use ($s) {
        $r = app(ReportService::class);
        $nov = Period::between('2026-11-01', '2026-11-15');

        expect($r->noShowRate($nov))->toBe(['no_shows' => 1, 'completed' => 3, 'rate' => 25.0])
            ->and($r->rebookRate($nov))->toMatchArray(['visits' => 3, 'rebooked' => 2, 'rate' => 66.7]);

        $monday = Period::day('2026-11-16');
        expect($r->utilization($monday, ['branch_id' => $s->branch->id]))->toBe(['available_minutes' => 360, 'booked_minutes' => 90, 'rate' => 25.0]);
        $heat = $r->utilizationHeatmap($monday, ['branch_id' => $s->branch->id]);
        expect($heat['matrix'][1][10])->toBe(50.0)->and($heat['matrix'][1][11])->toBe(25.0)->and($heat['matrix'][1][13])->toBe(0.0)
            ->and($heat['matrix'][1][9])->toBeNull()->and($heat['hours'])->toBe([10, 11, 12, 13]);

        $cohorts = collect($r->retentionCohorts(3))->keyBy('cohort');
        expect($cohorts['2026-10'])->toMatchArray(['size' => 1, 'retention' => [100.0]])
            ->and($cohorts['2026-11']['size'])->toBe(1);
    });
});

it('renders report pages for the owner, exports Excel/PDF, and forbids revenue pages to stylists', function () {
    $s = reportsFixture();
    $owner = $s->tenant->owner;
    foreach (['reports/revenue', 'reports/performance', 'reports/scorecards', 'reports/campaigns', 'settings/insights'] as $path) {
        $this->actingAs($owner)->get(tenantUrl($s->tenant, 'dashboard/'.$path))->assertOk();
    }
    $this->actingAs($owner)->get(tenantUrl($s->tenant, 'dashboard/reports/revenue'))->assertSee('Layla')->assertSee('Silk Oil');

    $stylist = tenantUser($s->tenant, 'stylist', [$s->branch->id]);
    foreach (['reports/revenue', 'reports/performance', 'reports/campaigns'] as $path) {
        $this->flushSession();
        $this->actingAs($stylist)->get(tenantUrl($s->tenant, 'dashboard/'.$path))->assertForbidden();
    }

    Excel::fake();
    $this->flushSession();
    $this->actingAs($owner);
    asTenant($s->tenant, function () {
        Filament::setCurrentPanel('dashboard');
        $page = Livewire::test(RevenueReport::class)->call('setPeriod', 'this_month');
        $page->callAction('exportExcel');
        Excel::assertDownloaded('revenue-2026-11-01-2026-11-18.xlsx', fn ($export) => count($export->sections) === 5
            && $export->sections[0]['rows'][0][3] === 1050.0);
        $page->callAction('exportPdf')->assertFileDownloaded('revenue-2026-11-01-2026-11-18.pdf');
    });

    $manager = tenantUser($s->tenant, 'marketing');
    $this->flushSession();
    $this->actingAs($manager)->get(tenantUrl($s->tenant, 'dashboard/reports/performance'))->assertOk();
    $this->flushSession();
    $this->actingAs($manager)->get(tenantUrl($s->tenant, 'dashboard/reports/revenue'))->assertForbidden();
});

it('sends the opt-in morning report once at 08:00 branch time', function () {
    MessagingManager::fake();
    $s = reportsFixture();
    $s->tenant->owner->update(['phone' => '+97455599999']);
    asTenant($s->tenant, fn () => InsightSettings::put(['morning_report' => ['enabled' => true, 'whatsapp' => true]]));
    $quiet = insightsSalon(); // not opted in

    $job = app(SendMorningReports::class);
    expect($job->handle(CarbonImmutable::parse('2026-11-19 07:50', 'Asia/Qatar')))->toBe(0);
    expect($job->handle(CarbonImmutable::parse('2026-11-19 08:05', 'Asia/Qatar')))->toBe(2); // two branches
    expect($job->handle(CarbonImmutable::parse('2026-11-19 08:20', 'Asia/Qatar')))->toBe(0); // idempotent

    expect(FakeChannel::sentTo('email', $s->tenant->owner->email))->toHaveCount(2)
        ->and(FakeChannel::sentTo('whatsapp', '+97455599999'))->toHaveCount(2)
        ->and(FakeChannel::sentTo('email', $quiet->tenant->owner->email))->toBeEmpty()
        ->and(asTenant($s->tenant, fn () => ReportDelivery::count()))->toBe(2);
    expect(FakeChannel::sentTo('whatsapp', '+97455599999')[0]['message']->body)->toContain('Good morning');

    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description);
    expect($events)->toContain('insights:morning-report');
});
