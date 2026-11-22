<?php

use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Catalog\Models\Service;
use App\Domain\Insights\Actions\ApplyInsightAction;
use App\Domain\Insights\Actions\GenerateInsights;
use App\Domain\Insights\Analytics\ChurnRisk;
use App\Domain\Insights\Analytics\DemandForecast;
use App\Domain\Insights\Analytics\ReorderAdvisor;
use App\Domain\Insights\Ask\AskYourData;
use App\Domain\Insights\Jobs\GenerateTenantInsights;
use App\Domain\Insights\Models\InsightCard;
use App\Domain\Insights\Models\InsightRun;
use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Inventory\Models\PurchaseOrder;
use App\Domain\Marketing\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/InsightsKit.php';
require_once __DIR__.'/../AIStudio/helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 03:00', 'Asia/Qatar'));
});

/** Fake Claude: echoes every card key back with "AI" copy in both languages. */
function fakeClaudeInsightWriter(): void
{
    Http::fake(['api.anthropic.com/*' => function (Request $request) {
        $prompt = $request->data()['messages'][0]['content'][0]['text'];
        $cards = json_decode(substr($prompt, strpos($prompt, 'Cards: ') + 7), true) ?: [];

        return Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['cards' => array_map(fn ($c) => [
            'key' => $c['key'], 'en' => ['title' => 'AI: '.$c['type'], 'body' => 'Refined copy'], 'ar' => ['title' => 'رؤية: '.$c['type'], 'body' => 'نص منقح'],
        ], $cards)])]]]);
    }]);
}

/** A salon with churn risk, low stock, quiet windows and recent sales. */
function insightfulSalon(string $serviceName = 'Cut', string $clientName = 'Noura Private'): object
{
    $s = insightsSalon();
    $client = insightsClient($s, '+9745550'.random_int(1000, 9999), $clientName);
    foreach (['2026-07-20', '2026-08-19', '2026-09-18'] as $d) { // 30-day cycle, last visit 61 days ago
        insightsBooking($s, $d.' 11:00', ['cut'], client: $client);
    }
    asTenant($s->tenant, function () use ($s, $serviceName) {
        $s->services['cut']->update(['name' => ['en' => $serviceName, 'ar' => $serviceName]]);
        app(StockLedger::class)->move($s->branch->id, $s->variant, 2, 'purchase');
    });
    insightsSale($s, '2026-11-15 12:00', [['service' => 'cut', 'staff' => 0, 'total' => 30000]]);
    $s->client = $client;

    return $s;
}

it('generates insight cards nightly per tenant with zero cross-tenant data', function () {
    aiProviders();
    fakeClaudeInsightWriter();
    $a = insightfulSalon('Signature Cut', 'Noura Private');
    $b = insightfulSalon('Keratin Gloss', 'Hessa Secret');
    aiCredits($a->tenant, 20); // B has no credits → template copy

    expect(GenerateTenantInsights::dispatchForAllTenants())->toBeGreaterThanOrEqual(2); // sync queue in tests

    $aCards = asTenant($a->tenant, fn () => InsightCard::all());
    $bCards = asTenant($b->tenant, fn () => InsightCard::all());
    expect($aCards->pluck('type')->all())->toContain('off_peak', 'reorder', 'churn_risk')
        ->and($bCards->pluck('type')->all())->toContain('off_peak', 'reorder', 'churn_risk')
        ->and($aCards->every(fn ($c) => $c->tenant_id === $a->tenant->id))->toBeTrue()
        ->and($bCards->every(fn ($c) => $c->tenant_id === $b->tenant->id))->toBeTrue()
        ->and($aCards->firstWhere('type', 'off_peak')->source)->toBe('ai')
        ->and($aCards->firstWhere('type', 'off_peak')->localized('title', 'ar'))->toBe('رؤية: off_peak')
        ->and($bCards->firstWhere('type', 'off_peak')->source)->toBe('template');

    // Each tenant's cards only carry its own aggregated numbers.
    expect(json_encode($aCards->pluck('metrics')))->not->toContain('Keratin')
        ->and(json_encode($bCards->pluck('metrics')))->not->toContain('Signature Cut')
        ->and($aCards->firstWhere('type', 'reorder')->action['params']['items'][0]['product_variant_id'])->toBe($a->variant->id);

    Http::assertSentCount(1);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $r) use ($a, $b) {
        $body = json_encode($r->data(), JSON_UNESCAPED_UNICODE);

        return str_contains($body, 'off_peak') && ! str_contains($body, 'Keratin') && ! str_contains($body, 'Hessa')
            && ! str_contains($body, 'Noura') && ! str_contains($body, $a->client->phone) && ! str_contains($body, $b->client->phone);
    });

    // Idempotent: a second nightly run the same day neither duplicates cards nor charges again.
    GenerateTenantInsights::dispatchForAllTenants();
    asTenant($a->tenant, fn () => app(GenerateInsights::class)->handle($a->tenant, force: true));
    expect(asTenant($a->tenant, fn () => InsightCard::count()))->toBe($aCards->count())
        ->and(asTenant($a->tenant, fn () => InsightRun::count()))->toBe(1)
        ->and(AIWallet::for($a->tenant)->balance)->toBe(19); // forced re-run re-asks AI but is charged once per day
});

it('turns insight cards into campaign and purchase order drafts with one click', function () {
    $s = insightfulSalon();
    $this->actingAs($s->tenant->owner);
    asTenant($s->tenant, function () use ($s) {
        app(GenerateInsights::class)->handle($s->tenant);
        $offPeak = InsightCard::where('type', 'off_peak')->firstOrFail();
        $reorder = InsightCard::where('type', 'reorder')->firstOrFail();
        $churn = InsightCard::where('type', 'churn_risk')->firstOrFail();

        app(ApplyInsightAction::class)->handle($offPeak);
        $campaign = Campaign::findOrFail($offPeak->fresh()->result['campaign_id']);
        expect($campaign->status)->toBe('draft')->and($campaign->segment)->not->toBeNull()
            ->and($campaign->content['en']['body'])->toContain('15% off')->and($campaign->content['ar']['body'])->toContain('15%')
            ->and($offPeak->fresh()->status)->toBe('done');

        app(ApplyInsightAction::class)->handle($reorder);
        $po = PurchaseOrder::findOrFail($reorder->fresh()->result['purchase_order_ids'][0]);
        expect($po->status)->toBe('draft')->and($po->items->first()->product_variant_id)->toBe($s->variant->id);

        // Acting twice is a no-op; dismiss hides the card.
        app(ApplyInsightAction::class)->handle($offPeak->fresh());
        expect(Campaign::count())->toBe(1);
        app(ApplyInsightAction::class)->dismiss($churn);
        expect($churn->fresh()->status)->toBe('dismissed');
    });

    $this->get(tenantUrl($s->tenant, 'dashboard/insights'))->assertOk()->assertSee(__('insights.hub.tabs.ask'));
    foreach (['churn', 'forecast', 'reorder', 'pricing', 'ask'] as $tab) {
        $this->get(tenantUrl($s->tenant, 'dashboard/insights?tab='.$tab))->assertOk();
    }
    $this->get(tenantUrl($s->tenant, 'dashboard/insights?tab=churn'))->assertSee('Noura Private');
});

it('lists churn risk against each client’s own cycle', function () {
    $s = insightsSalon();
    $late = insightsClient($s, '+97455502001', 'Late Lina');
    $fine = insightsClient($s, '+97455502002', 'Fine Fatma');
    $booked = insightsClient($s, '+97455502003', 'Booked Basma');
    $once = insightsClient($s, '+97455502004', 'Once Omnia');
    foreach (['2026-08-01', '2026-08-22', '2026-09-12'] as $d) {
        insightsBooking($s, $d.' 11:00', ['cut'], client: $late);   // 21-day cycle, 67 days ago → 3.19×
        insightsBooking($s, $d.' 12:00', ['cut'], client: $booked); // same, but has an upcoming booking
    }
    insightsBooking($s, '2026-11-25 12:00', ['cut'], client: $booked, status: BookingStatus::Confirmed);
    foreach (['2026-09-01', '2026-11-01'] as $d) {
        insightsBooking($s, $d.' 13:00', ['cut'], client: $fine);   // 61-day cycle, 17 days ago
    }
    insightsBooking($s, '2026-06-01 13:00', ['cut'], client: $once);

    $list = asTenant($s->tenant, fn () => app(ChurnRisk::class)->list());
    expect($list)->toHaveCount(1)
        ->and($list[0])->toMatchArray(['name' => 'Late Lina', 'cycle_days' => 21, 'days_since' => 67, 'overdue_ratio' => 3.19])
        ->and($list[0]['message'])->toContain('Late');
});

it('suggests reorder quantities from usage and forecasts demand explainably', function () {
    $s = insightsSalon();
    asTenant($s->tenant, function () use ($s) {
        $ledger = app(StockLedger::class);
        $ledger->move($s->branch->id, $s->variant, 33, 'purchase');
        for ($i = 0; $i < 28; $i++) {
            $m = $ledger->move($s->branch->id, $s->variant, -1, 'sale');
            $m->forceFill(['created_at' => now()->subDays($i)])->save();
        }
        $suggestion = collect(app(ReorderAdvisor::class)->suggestions())->firstWhere('variant_id', $s->variant->id);
        expect($suggestion)->toMatchArray(['on_hand' => 5, 'daily_usage' => 1.0, 'days_cover' => 5.0, 'suggested_qty' => 28]); // 30 + 3 − 5
    });

    // Last four Mondays: 2, 4, 2, 4 bookings → average 3, flat trend.
    foreach (['2026-10-26' => 2, '2026-11-02' => 4, '2026-11-09' => 2, '2026-11-16' => 4] as $date => $n) {
        for ($i = 0; $i < $n; $i++) {
            insightsBooking($s, $date.' '.(10 + $i).':00', ['cut'], $s->staff[$i % 2]);
        }
    }
    $days = collect(asTenant($s->tenant, fn () => app(DemandForecast::class)->forBranch($s->branch, 14)))->keyBy('date');
    expect($days['2026-11-23'])->toMatchArray(['history' => [4, 2, 4, 2], 'average' => 3.0, 'forecast' => 3.0, 'staff_needed' => 1]);
});

it('answers ask-your-data questions only from whitelisted read-only queries', function () {
    $s = insightsSalon();
    insightsSale($s, '2026-11-12 16:00', [['service' => 'color', 'staff' => 0, 'total' => 40000]], branch: $s->second);
    insightsSale($s, '2026-11-12 17:00', [['service' => 'cut', 'staff' => 0, 'total' => 20000]], branch: $s->second);
    insightsSale($s, '2026-11-13 12:00', [['service' => 'color', 'staff' => 1, 'total' => 40000]]);
    $this->actingAs($s->tenant->owner);

    asTenant($s->tenant, function () use ($s) {
        $s->category->update(['name' => ['en' => 'Color', 'ar' => 'صبغة']]);
        Service::whereKey($s->services['cut']->id)->update(['service_category_id' => null]);
        $ask = app(AskYourData::class);

        // Keyword fallback (no AI credits): branch + category + month.
        $answer = $ask->ask('How much did Pearl make from color services in November?');
        expect($answer['ok'])->toBeTrue()->and($answer['source'])->toBe('rules')
            ->and($answer['plan'])->toBe(['metric' => 'revenue', 'filters' => ['branch' => 'Pearl', 'service_category' => 'Color'], 'period' => '2026-11'])
            ->and($answer['rows'][0][1])->toBe(money(40000))
            ->and($answer['answer'])->toContain(money(40000))->toContain('Pearl');

        // Whitelist rejections: unknown metric, raw SQL, unknown filter, extra keys, bad period.
        foreach ([
            ['metric' => 'DROP TABLE sales'],
            ['metric' => 'revenue', 'filters' => ['client_phone' => '+974']],
            ['metric' => 'revenue', 'sql' => 'select * from clients'],
            ['metric' => 'revenue', 'filters' => ['branch' => 'Nowhere']],
            ['metric' => 'revenue', 'period' => "2026-11'; --"],
            'select * from sales',
        ] as $plan) {
            expect($ask->answerPlan($plan)['ok'])->toBeFalse();
        }
    });

    // AI planner: Claude only returns a plan; the numbers come from ReportService.
    aiProviders();
    aiCredits($s->tenant, 5);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '{"metric":"revenue","filters":{"branch":"Main"},"period":"this_month"}']]])
        ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'SELECT SUM(total) FROM sales; DROP TABLE clients;']]]),
    ]);
    asTenant($s->tenant, function () {
        $ai = app(AskYourData::class)->ask('What did Main bring in this month?');
        expect($ai['source'])->toBe('ai')->and($ai['rows'][0][1])->toBe(money(40000));
        $sql = app(AskYourData::class)->ask('Give me everything');
        expect($sql['source'])->toBe('rules')->and($sql['ok'])->toBeFalse();
    });

    // Revenue questions need reports.revenue.
    $this->actingAs(tenantUser($s->tenant, 'marketing'));
    asTenant($s->tenant, fn () => expect(app(AskYourData::class)->answerPlan(['metric' => 'revenue', 'period' => 'this_month'])['ok'])->toBeFalse()
        ->and(app(AskYourData::class)->answerPlan(['metric' => 'bookings', 'period' => 'this_month'])['ok'])->toBeTrue());
});
