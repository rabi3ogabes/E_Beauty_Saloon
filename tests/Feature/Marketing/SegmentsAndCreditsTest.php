<?php

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\BookingItem;
use App\Domain\CRM\Models\Tag;
use App\Domain\Marketing\Actions\LaunchCampaign;
use App\Domain\Marketing\Models\MessageCreditTransaction;
use App\Domain\Marketing\Models\PlatformMessageChannel;
use App\Domain\Marketing\Models\TenantChannelSetting;
use App\Domain\Marketing\Services\MessageCredits;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\MessagingManager;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Marketing\MarketingFixtures as F;

it('applies segment rules', function () {
    $tenant = createTenant();
    asTenant($tenant, function () {
        $recent = F::client(['last_visit_at' => now()->subDays(5), 'visits_count' => 3, 'lifetime_value' => 250000, 'loyalty_tier' => 'gold']);
        $lapsed = F::client(['last_visit_at' => now()->subDays(90), 'visits_count' => 1, 'lifetime_value' => 20000]);
        $birthday = F::client(['birthday' => now()->subYears(30), 'gender' => 'female']);
        $tagged = F::client();
        $tagged->tags()->attach(Tag::create(['name' => 'VIP'])->id);

        $service = F::service();
        $booking = F::booking($recent, ['status' => BookingStatus::Completed]);
        BookingItem::create(['booking_id' => $booking->id, 'service_id' => $service->id, 'starts_at' => now(), 'ends_at' => now()->addHour(), 'price' => 15000, 'duration_minutes' => 45]);

        $ids = fn (array $conditions, string $match = 'all') => F::segment($conditions, $match)->clients()->pluck('id')->sort()->values()->all();

        expect($ids([['field' => 'visited_within_days', 'value' => 30]]))->toBe([$recent->id])
            ->and($ids([['field' => 'not_visited_days', 'value' => 60]]))->toBe([$lapsed->id])
            ->and($ids([['field' => 'spent_over', 'value' => 1000]]))->toBe([$recent->id])
            ->and($ids([['field' => 'birthday_this_month']]))->toBe([$birthday->id])
            ->and($ids([['field' => 'bought_service', 'value' => [$service->id]]]))->toBe([$recent->id])
            ->and($ids([['field' => 'tags', 'value' => [$tagged->tags->first()?->id ?? Tag::first()->id]]]))->toBe([$tagged->id])
            ->and($ids([['field' => 'loyalty_tier', 'value' => ['gold']]]))->toBe([$recent->id])
            ->and($ids([['field' => 'ai_studio_users']]))->toBe([])
            ->and($ids([['field' => 'bought_product', 'value' => [1]]]))->toBe([])
            ->and($ids([['field' => 'visited_within_days', 'value' => 30], ['field' => 'birthday_this_month']], 'any'))->toBe(collect([$recent->id, $birthday->id])->sort()->values()->all())
            ->and($ids([['field' => 'visited_within_days', 'value' => 30], ['field' => 'birthday_this_month']], 'all'))->toBe([]);
    });

    // tenant isolation: another salon's segment never sees these clients
    $other = createTenant();
    asTenant($other, fn () => expect(F::segment()->clients()->count())->toBe(0));
});

it('debits platform-shared credits per message and hard-stops when the balance is insufficient', function () {
    MessagingManager::fake(false);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    PlatformMessageChannel::create(['channel' => 'sms', 'provider' => 'twilio', 'price_per_message' => 25, 'is_active' => true,
        'credentials' => ['account_sid' => 'AC1', 'auth_token' => 'x', 'from' => 'LUXE']]);
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant) {
        TenantChannelSetting::create(['channel' => 'sms', 'mode' => 'platform_shared']);
        $credits = app(MessageCredits::class);
        $credits->topUp($tenant->id, 50, 'test');

        $send = fn () => app(MessagingManager::class)->send('sms', new OutboundMessage('+97455001234', 'Hello'));
        expect($send()->ok)->toBeTrue()->and($send()->ok)->toBeTrue();
        expect($credits->balance($tenant->id))->toBe(0);

        $third = $send();
        expect($third->ok)->toBeFalse()->and($third->error)->toBe(MessageCredits::INSUFFICIENT);
        Http::assertSentCount(2);
        expect(MessageCreditTransaction::where('type', 'debit')->count())->toBe(2);
    });
});

it('refunds the credit when the shared provider rejects the message', function () {
    MessagingManager::fake(false);
    Http::fake(['api.twilio.com/*' => Http::response(['message' => 'invalid number'], 400)]);
    PlatformMessageChannel::create(['channel' => 'sms', 'provider' => 'twilio', 'price_per_message' => 25, 'is_active' => true,
        'credentials' => ['account_sid' => 'AC1', 'auth_token' => 'x', 'from' => 'LUXE']]);
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant) {
        TenantChannelSetting::create(['channel' => 'sms', 'mode' => 'platform_shared']);
        app(MessageCredits::class)->topUp($tenant->id, 100);
        $result = app(MessagingManager::class)->send('sms', new OutboundMessage('+97455001234', 'Hello'));

        expect($result->ok)->toBeFalse()->and(app(MessageCredits::class)->balance($tenant->id))->toBe(100);
    });
});

it('refuses to launch a campaign whose estimated cost exceeds the credit balance', function () {
    MessagingManager::fake();
    PlatformMessageChannel::create(['channel' => 'whatsapp', 'provider' => 'meta', 'price_per_message' => 40, 'is_active' => true]);
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant) {
        TenantChannelSetting::create(['channel' => 'whatsapp', 'mode' => 'platform_shared']);
        F::client();
        F::client();
        app(MessageCredits::class)->topUp($tenant->id, 60);
        $campaign = F::campaign(F::segment(), ['channels' => ['whatsapp']]);

        expect(fn () => app(LaunchCampaign::class)->handle($campaign))->toThrow(DomainException::class);
        expect($campaign->fresh()->status)->toBe('draft');
    });
});
