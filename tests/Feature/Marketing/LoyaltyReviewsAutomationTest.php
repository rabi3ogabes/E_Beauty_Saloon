<?php

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\BookingItem;
use App\Domain\CRM\Models\Client;
use App\Domain\Loyalty\Actions\LoyaltyLedger;
use App\Domain\Loyalty\Models\LoyaltyEntry;
use App\Domain\Marketing\Actions\InstallAutomationRecipes;
use App\Domain\Marketing\Automation\AutomationEngine;
use App\Domain\Marketing\Models\Automation;
use App\Domain\Marketing\Models\AutomationRun;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Models\Referral;
use App\Domain\Marketing\Models\Review;
use App\Domain\Marketing\Services\MarketingScheduler;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Team\Models\Staff;
use Illuminate\Support\Facades\Blade;
use Tests\Feature\Marketing\MarketingFixtures as F;

beforeEach(function () {
    MessagingManager::fake();
    $this->tenant = createTenant();
    F::noQuietHours($this->tenant);
    $this->tenant = $this->tenant->fresh();
});

it('earns loyalty points once when a booking completes (shared idempotency key with POS)', function () {
    asTenant($this->tenant, function () {
        $client = F::client();
        $booking = F::booking($client, ['total' => 45000]);
        $booking->update(['status' => BookingStatus::Completed, 'completed_at' => now()]);

        expect($client->fresh()->loyalty_points)->toBe(450)
            ->and(LoyaltyEntry::where('idempotency_key', 'loyalty:booking:'.$booking->id)->count())->toBe(1);

        // POS settling the same booking with the same key never double-earns
        app(LoyaltyLedger::class)->earnForSpend($client, 45000, $booking, 'loyalty:booking:'.$booking->id);
        expect($client->fresh()->loyalty_points)->toBe(450);
    });
});

it('rewards the referrer after the friend’s first completed visit', function () {
    $referrer = asTenant($this->tenant, fn () => F::client(['name' => 'Noura Al-Kuwari']));

    $this->get(tenantUrl($this->tenant, 'r/'.$referrer->referral_code))->assertOk()->assertSee('Noura');
    $this->post(tenantUrl($this->tenant, 'login/code'), ['phone' => '5555 4321']);
    $this->post(tenantUrl($this->tenant, 'login/verify'), ['phone' => '+97455554321', 'code' => '123456', 'name' => 'Dana'])->assertRedirect();

    asTenant($this->tenant, function () use ($referrer) {
        $friend = Client::where('phone', '+97455554321')->firstOrFail();
        $referral = Referral::firstOrFail();
        expect($referral->referrer_client_id)->toBe($referrer->id)
            ->and($friend->referred_by_client_id)->toBe($referrer->id)
            ->and(Promotion::find($referral->promotion_id)->rule('client_id'))->toBe($friend->id);

        $booking = F::booking($friend, ['total' => 10000]);
        $booking->update(['status' => BookingStatus::Completed]);

        expect($referral->fresh()->status)->toBe('rewarded')
            ->and($referrer->fresh()->loyalty_points)->toBe(500);
    });
});

it('routes low ratings privately to the owner and publishes good ones', function () {
    $this->tenant->update(['phone' => '+97444001122']);
    [$low, $high, $staff] = asTenant($this->tenant, function () {
        $staff = Staff::create(['name' => ['en' => 'Layla'], 'rating' => 4.0, 'ratings_count' => 1]);
        $make = function (string $name) use ($staff) {
            $booking = F::booking(F::client(['name' => $name]), ['status' => BookingStatus::Completed]);
            BookingItem::create(['booking_id' => $booking->id, 'service_id' => F::service()->id, 'staff_id' => $staff->id, 'starts_at' => now(), 'ends_at' => now()->addHour(), 'price' => 15000, 'duration_minutes' => 45]);

            return $booking;
        };

        return [$make('Unhappy Client'), $make('Delighted Client'), $staff];
    });

    $this->get(tenantUrl($this->tenant, 'review/'.strtolower($low->reference)))->assertOk()->assertSee(__('marketing.reviews.submit'));
    $this->post(tenantUrl($this->tenant, 'review/'.$low->reference), ['rating' => 2, 'comment' => 'Waited 40 minutes'])->assertRedirect();
    $this->post(tenantUrl($this->tenant, 'review/'.$high->reference), ['rating' => 5, 'comment' => 'Absolutely perfect balayage'])->assertRedirect();

    asTenant($this->tenant, function () use ($low, $high, $staff) {
        expect(Review::where('booking_id', $low->id)->value('status'))->toBe('private')
            ->and(Review::where('booking_id', $high->id)->value('status'))->toBe('published')
            ->and($this->tenant->owner->notifications()->count())->toBe(1)
            ->and(FakeChannel::sentTo('whatsapp', '+97444001122'))->toHaveCount(1)
            ->and((float) $staff->fresh()->rating)->toBe(3.67)
            ->and($staff->fresh()->ratings_count)->toBe(3);

        $html = Blade::render('<x-marketing.reviews :limit="6" />');
        expect($html)->toContain('Absolutely perfect balayage')->not->toContain('Waited 40 minutes');
    });

    $this->get(tenantUrl($this->tenant, 'review/'.$high->reference))->assertSee(__('marketing.reviews.thanks_title'));
});

it('fires the birthday recipe from the daily scan once per year', function () {
    asTenant($this->tenant, function () {
        app(InstallAutomationRecipes::class)->handle();
        $client = F::client(['birthday' => now('Asia/Qatar')->subYears(30)]);
        F::client(['birthday' => now('Asia/Qatar')->subYears(30)->addDays(3)]);
    });

    app(MarketingScheduler::class)->daily();
    app(MarketingScheduler::class)->daily();

    asTenant($this->tenant, function () {
        $sent = FakeChannel::sentTo('whatsapp');
        $birthdayRun = AutomationRun::where('trigger', 'birthday')->get();
        expect($birthdayRun)->toHaveCount(1)
            ->and($birthdayRun->first()->status)->toBe('completed')
            ->and(collect($sent)->filter(fn ($s) => str_contains($s['message']->body, 'Happy birthday'))->count())->toBe(1)
            ->and(Client::find($birthdayRun->first()->client_id)->loyalty_points)->toBe(200)
            ->and(Promotion::where('code', $birthdayRun->first()->context['code'])->exists())->toBeTrue();
    });
});

it('fires the win-back recipe for clients who have not visited for 60 days, then follows up', function () {
    asTenant($this->tenant, function () {
        app(InstallAutomationRecipes::class)->handle();
        F::client(['last_visit_at' => now()->subDays(61), 'visits_count' => 4]);
        F::client(['last_visit_at' => now()->subDays(10), 'visits_count' => 4]);
    });

    app(MarketingScheduler::class)->daily();

    asTenant($this->tenant, function () {
        $run = AutomationRun::where('trigger', 'win_back')->sole();
        expect($run->status)->toBe('waiting')
            ->and(collect(FakeChannel::sentTo('whatsapp'))->filter(fn ($s) => str_contains($s['message']->body, 'We miss you'))->count())->toBe(1);
    });

    $this->travel(11)->days();
    app(MarketingScheduler::class)->frequent();

    asTenant($this->tenant, function () {
        expect(AutomationRun::where('trigger', 'win_back')->first()->status)->toBe('completed')
            ->and(collect(FakeChannel::sentTo('whatsapp'))->filter(fn ($s) => str_contains($s['message']->body, 'is waiting'))->count())->toBe(1);
    });
});

it('fires the abandoned cart recipe from AutomationEngine::fire() without shop classes', function () {
    asTenant($this->tenant, function () {
        app(InstallAutomationRecipes::class)->handle();
        $client = F::client();

        $runs = app(AutomationEngine::class)->fire('abandoned_cart', $client, ['dedupe' => 'cart:7', 'cart_url' => 'https://shop.test/cart/7']);
        app(AutomationEngine::class)->fire('abandoned_cart', $client, ['dedupe' => 'cart:7']); // duplicate event ignored

        expect($runs)->toHaveCount(1)->and($runs[0]->status)->toBe('waiting')->and(AutomationRun::where('trigger', 'abandoned_cart')->count())->toBe(1);
    });

    $this->travel(2)->hours();
    app(MarketingScheduler::class)->frequent();

    expect(collect(FakeChannel::sentTo('whatsapp'))->filter(fn ($s) => str_contains($s['message']->body, 'https://shop.test/cart/7'))->count())->toBe(1);
});

it('does not run automations when the marketing module is disabled', function () {
    $tenant = createTenant('signature'); // loyalty, no marketing
    asTenant($tenant, function () {
        Automation::create(['name' => 'Cart', 'trigger' => 'abandoned_cart', 'steps' => [['type' => 'send_message', 'body' => ['en' => 'x']]], 'is_active' => true]);
        expect(app(AutomationEngine::class)->fire('abandoned_cart', F::client()))->toBe([]);
    });
});
