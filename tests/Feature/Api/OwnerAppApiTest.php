<?php

use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\Booking\Actions\CreateBooking;
use App\Domain\Booking\Actions\MarkNoShow;
use App\Domain\Branches\Models\Branch;
use App\Domain\CRM\Models\Client;
use App\Domain\Inventory\Models\BranchStock;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Domain\Marketing\Models\Review;
use App\Domain\Messaging\MessagingManager;
use App\Domain\POS\Actions\CompleteSale;
use App\Domain\Team\Models\TimeOff;
use Carbon\CarbonImmutable;
use Tests\Feature\Marketing\MarketingFixtures as F;

require_once __DIR__.'/../Booking/Helpers.php';
require_once __DIR__.'/../Pos/PosTestHelpers.php';
require_once __DIR__.'/ApiHelpers.php';

beforeEach(function () {
    MessagingManager::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-30 13:00', 'Asia/Qatar'));
    $this->s = posSalon(['tax_percent' => 0, 'timezone' => 'Asia/Qatar']);
    $this->owner = $this->s->owner;
    $this->h = apiHeaders($this->s->tenant, teamToken($this->owner, 'owner'));
});

it('signs the owner in to the owner app', function () {
    $this->postJson(apiUrl('owner/auth/login'), ['email' => $this->owner->email, 'password' => 'password-1234', 'device_name' => 'iPad'], apiHeaders($this->s->tenant))
        ->assertOk()->assertJsonPath('abilities', ['owner'])->assertJsonPath('user.is_owner', true)->assertJsonPath('user.branch_ids', null);

    $receptionist = tenantUser($this->s->tenant, 'receptionist');
    $this->postJson(apiUrl('owner/auth/login'), ['email' => $receptionist->email, 'password' => 'password-1234', 'device_name' => 'x'], apiHeaders($this->s->tenant))
        ->assertForbidden()->assertJsonPath('error.code', 'app_not_allowed');
});

it('computes today\'s KPIs from POS sales, shop orders, bookings and new clients', function () {
    asTenant($this->s->tenant, function () {
        $sale = fn (int $tendered, array $lines) => app(CompleteSale::class)->handle([
            'idempotency_key' => saleKey(), 'branch_id' => $this->s->branch->id, 'lines' => $lines,
            'payments' => [['method' => 'cash', 'tendered' => $tendered]],
        ], $this->owner);
        $sale(15000, [['type' => 'service', 'id' => $this->s->services[0]->id, 'staff_id' => $this->s->layla->id]]); // 150
        $sale(9500, [['type' => 'product', 'id' => $this->s->products[0]->id]]);                                       // 95

        $this->s->layla->update(['is_bookable' => true]);
        $this->s->services[0]->branches()->attach($this->s->branch->id, ['is_active' => true]);
        $this->s->layla->services()->attach($this->s->services[0]->id);
        $book = fn (string $local) => app(CreateBooking::class)->handle([
            'branch' => $this->s->branch, 'items' => [['service_id' => $this->s->services[0]->id, 'staff_id' => $this->s->layla->id]],
            'starts_at' => CarbonImmutable::parse($local, 'Asia/Qatar'), 'client' => $this->s->client, 'source' => 'dashboard', 'online' => false, 'enforce_notice' => false,
        ]);
        $book('2026-11-30 15:00');
        $late = $book('2026-11-30 10:00');
        app(MarkNoShow::class)->handle($late);
    });

    $res = $this->getJson(apiUrl('owner/kpis'), $this->h)->assertOk()
        ->assertJsonPath('data.date', '2026-11-30')
        ->assertJsonPath('data.revenue_today.amount', 24500)
        ->assertJsonPath('data.revenue_yesterday.amount', 0)
        ->assertJsonPath('data.sales_count', 2)
        ->assertJsonPath('data.average_ticket.amount', 12250)
        ->assertJsonPath('data.bookings_today', 1)
        ->assertJsonPath('data.no_shows', 1)
        ->assertJsonPath('data.new_clients', 1);
    expect($res->json('data.utilization'))->toBeGreaterThan(0)->toBeLessThanOrEqual(1)
        ->and(array_sum($res->json('data.hourly_revenue')))->toBe(24500);

    $this->getJson(apiUrl('owner/kpis?branch_id='.$this->s->branch->id.'&date=2026-11-29'), $this->h)->assertOk()->assertJsonPath('data.revenue_today.amount', 0);
});

it('scopes KPIs to the member\'s branches and hides revenue without reports.revenue', function () {
    $other = asTenant($this->s->tenant, fn () => Branch::create(['name' => ['en' => 'Pearl', 'ar' => 'اللؤلؤة'], 'slug' => 'pearl', 'opening_hours' => Branch::defaultHours()]));
    $manager = tenantUser($this->s->tenant, 'branch_manager', [$this->s->branch->id]);
    $this->app['auth']->forgetGuards();
    $h = apiHeaders($this->s->tenant, teamToken($manager, 'owner'));

    $this->getJson(apiUrl('owner/kpis?branch_id='.$this->s->branch->id), $h)->assertOk()->assertJsonPath('data.branch_id', $this->s->branch->id);
    $this->getJson(apiUrl('owner/kpis?branch_id='.$other->id), $h)->assertNotFound();

    $marketing = tenantUser($this->s->tenant, 'marketing');
    $this->app['auth']->forgetGuards();
    $this->getJson(apiUrl('owner/kpis'), apiHeaders($this->s->tenant, teamToken($marketing, 'owner')))->assertOk()->assertJsonPath('data.revenue_today', null);
});

it('lists alerts: low stock, pending time off, low AI credits and bad reviews', function () {
    asTenant($this->s->tenant, function () {
        BranchStock::query()->where('product_variant_id', $this->s->products[0]->id)->update(['low_stock_threshold' => 20]);
        TimeOff::create(['staff_id' => $this->s->layla->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(4), 'status' => 'pending']);
        AIWallet::for($this->s->tenant)->forceFill(['balance' => 0])->save();
        Review::create(['client_id' => $this->s->client->id, 'rating' => 1, 'comment' => 'Waited 40 minutes', 'status' => 'private']);
        Review::create(['client_id' => $this->s->client->id, 'rating' => 5, 'comment' => 'Perfect']);
    });

    $alerts = collect($this->getJson(apiUrl('owner/alerts'), $this->h)->assertOk()->json('data'))->keyBy('type');
    expect($alerts->keys()->all())->toContain('low_stock', 'time_off', 'ai_credits', 'bad_review')
        ->and($alerts['low_stock']['count'])->toBe(1)->and($alerts['low_stock']['body'])->toContain('Elixir Oil')
        ->and($alerts['bad_review']['severity'])->toBe('critical')->and($alerts['bad_review']['body'])->toBe('Waited 40 minutes')
        ->and($alerts['ai_credits']['severity'])->toBe('critical')
        ->and($alerts['time_off']['body'])->toContain('Layla');
});

it('sends a quick campaign to a saved segment through the Marketing actions', function () {
    $segment = asTenant($this->s->tenant, function () {
        F::noQuietHours($this->s->tenant);
        F::client([], ['whatsapp']);
        F::client([], ['whatsapp']);
        F::client([], []); // no consent → skipped

        return F::segment();
    });

    $this->getJson(apiUrl('owner/segments'), $this->h)->assertOk()->assertJsonPath('data.0.id', $segment->id);
    $this->postJson(apiUrl('owner/campaigns'), ['audience' => (string) $segment->id, 'channel' => 'whatsapp', 'message' => 'Hi {first_name}, 20% off today: {link}'], $this->h + ['Idempotency-Key' => 'camp-1'])
        ->assertCreated()->assertJsonPath('data.recipients', 2)->assertJsonPath('message', __('api.owner.campaign_queued', ['count' => 2]));

    asTenant($this->s->tenant, function () {
        $campaign = Campaign::firstOrFail();
        expect($campaign->channels)->toBe(['whatsapp'])
            ->and(CampaignMessage::where('status', 'skipped_no_consent')->count())->toBe(2) // POS client + no-consent client
            ->and(CampaignMessage::whereIn('status', CampaignMessage::SENT_STATUSES)->count())->toBe(2);
    });
});

it('requires marketing.send for quick campaigns and the owner ability for owner endpoints', function () {
    $segment = asTenant($this->s->tenant, fn () => F::segment());
    $manager = tenantUser($this->s->tenant, 'branch_manager', [$this->s->branch->id]);
    $this->app['auth']->forgetGuards();
    $this->postJson(apiUrl('owner/campaigns'), ['segment_id' => $segment->id, 'channel' => 'sms', 'message' => 'Hi'], apiHeaders($this->s->tenant, teamToken($manager, 'owner')))
        ->assertForbidden()->assertJsonPath('error.code', 'forbidden');

    $this->app['auth']->forgetGuards();
    $this->getJson(apiUrl('owner/alerts'), apiHeaders($this->s->tenant, teamToken($this->owner, 'staff')))->assertForbidden();

    $client = asTenant($this->s->tenant, fn () => Client::firstOrFail());
    $this->app['auth']->forgetGuards();
    $this->getJson(apiUrl('owner/kpis'), apiHeaders($this->s->tenant, clientToken($client)))->assertForbidden();
});
