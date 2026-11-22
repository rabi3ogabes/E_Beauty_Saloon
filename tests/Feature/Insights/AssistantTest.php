<?php

use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\Assistant\Actions\GenerateContent;
use App\Domain\Assistant\Actions\HandleInboundMessage;
use App\Domain\Assistant\Contracts\AssistantModel;
use App\Domain\Assistant\Drivers\FakeAssistantModel;
use App\Domain\Assistant\DTOs\AssistantTurn;
use App\Domain\Assistant\Models\Conversation;
use App\Domain\Assistant\Support\AssistantSettings;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Marketing\Models\TenantChannelSetting;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;
use App\Filament\Dashboard\Pages\Assistant\AssistantInbox;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/InsightsKit.php';
require_once __DIR__.'/../AIStudio/helpers.php';

beforeEach(function () {
    MessagingManager::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-18 09:00', 'Asia/Qatar'));
});

function receptionistSalon(bool $enabled = true): object
{
    $s = insightsSalon();
    workSchedule($s, $s->staff[0], '10:00', '20:00');
    asTenant($s->tenant, function () use ($enabled) {
        AssistantSettings::put(['enabled' => $enabled, 'faq' => 'Free valet parking at the entrance.']);
        TenantChannelSetting::create(['channel' => 'whatsapp', 'provider' => 'meta', 'mode' => 'own',
            'credentials' => ['phone_number_id' => 'PN-LUX', 'token' => 't', 'app_secret' => 'wa-secret']]);
    });

    return $s;
}

function claudeSays(array $json): array
{
    return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode($json, JSON_UNESCAPED_UNICODE)]]];
}

function whatsappInbound(string $from, string $text, string $id = 'wamid.IN1', string $name = 'Mona'): void
{
    $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
        'metadata' => ['phone_number_id' => 'PN-LUX'],
        'contacts' => [['profile' => ['name' => $name], 'wa_id' => ltrim($from, '+')]],
        'messages' => [['from' => ltrim($from, '+'), 'id' => $id, 'type' => 'text', 'text' => ['body' => $text]]],
    ]]]]]]);
    test()->call('POST', centralUrl('webhooks/whatsapp'), [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'wa-secret'), 'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();
}

it('completes a booking end-to-end over WhatsApp with the AI receptionist (sandbox)', function () {
    aiProviders();
    $s = receptionistSalon();
    aiCredits($s->tenant, 20);
    $cut = $s->services['cut'];
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(claudeSays(['tool_calls' => [['name' => 'check_availability', 'arguments' => ['service_id' => $cut->id, 'date' => '2026-11-19']]]]))
        ->push(claudeSays(['tool_calls' => [['name' => 'book', 'arguments' => ['service_id' => $cut->id, 'date' => '2026-11-19', 'time' => '11:00', 'name' => 'Mona Saeed']]]]))
        ->push(claudeSays(['reply' => 'You are booked for a Cut tomorrow at 11:00 with Layla. See you soon!', 'confidence' => 0.96])),
    ]);

    whatsappInbound('+97455577777', 'Hi! Can I book a haircut tomorrow at 11?');

    asTenant($s->tenant, function () use ($s, $cut) {
        $booking = Booking::with('items')->sole();
        expect($booking->source)->toBe('whatsapp_ai')
            ->and($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->starts_at->copy()->setTimezone('Asia/Qatar')->format('Y-m-d H:i'))->toBe('2026-11-19 11:00')
            ->and($booking->items->first()->service_id)->toBe($cut->id)
            ->and($booking->client->name)->toBe('Mona Saeed')
            ->and($booking->client->phone)->toBe('+97455577777');

        $conversation = Conversation::sole();
        expect($conversation->status)->toBe('bot')->and($conversation->last_booking_id)->toBe($booking->id)
            ->and($conversation->messages)->toHaveCount(2);
        $reply = $conversation->messages->last();
        expect($reply->author)->toBe('bot')->and(collect($reply->tool_calls)->pluck('name')->all())->toBe(['check_availability', 'book'])
            ->and($reply->tool_calls[0]['result']['times'])->not->toBeEmpty()
            ->and($reply->tool_calls[1]['result']['booked'])->toBeTrue();
        expect(AIWallet::for($s->tenant)->balance)->toBe(17); // one credit per model step
    });

    $sent = collect(FakeChannel::sentTo('whatsapp', '+97455577777'));
    expect($sent->pluck('message.body')->implode("\n"))->toContain('You are booked for a Cut tomorrow at 11:00')
        ->and($sent->contains(fn ($m) => ($m['message']->meta['booking_id'] ?? null) !== null))->toBeTrue(); // booking confirmation from BookingNotifier

    // Webhook retries are idempotent.
    whatsappInbound('+97455577777', 'Hi! Can I book a haircut tomorrow at 11?');
    expect(asTenant($s->tenant, fn () => Booking::count()))->toBe(1);
    Http::assertSentCount(3);
});

it('hands over to a human on request, in the client language, and stays quiet afterwards', function () {
    $s = receptionistSalon();
    $model = new FakeAssistantModel([AssistantTurn::reply('should not be used')]);
    app()->instance(AssistantModel::class, $model);

    whatsappInbound('+97455588888', 'مرحبا، أريد التحدث مع موظف من فضلك', 'wamid.AR1');
    whatsappInbound('+97455588888', 'متى تفتحون؟', 'wamid.AR2');

    $conversation = asTenant($s->tenant, fn () => Conversation::with('messages')->sole());
    expect($conversation->status)->toBe('handoff')->and($conversation->handoff_reason)->toBe('requested')
        ->and($conversation->locale)->toBe('ar')->and($conversation->unread_count)->toBe(2)
        ->and($conversation->messages)->toHaveCount(3)
        ->and($model->calls)->toBeEmpty();
    expect(FakeChannel::sentTo('whatsapp', '+97455588888'))->toHaveCount(1)
        ->and(FakeChannel::sentTo('whatsapp', '+97455588888')[0]['message']->body)->toContain('ستتواصل معك');
});

it('answers FAQs with tools, hands off on low confidence, and respects the owner switch and hours', function () {
    $s = receptionistSalon();
    $model = new FakeAssistantModel([
        AssistantTurn::tools(['salon_info' => []]),
        AssistantTurn::reply('Yes — free valet parking at the entrance.', 0.92),
        AssistantTurn::reply('Maybe?', 0.2),
    ]);
    app()->instance(AssistantModel::class, $model);

    asTenant($s->tenant, function () use ($model) {
        $faq = app(HandleInboundMessage::class)->handle('+97455599990', 'Do you have parking?', 'wamid.F1');
        expect($faq->body)->toBe('Yes — free valet parking at the entrance.')
            ->and($faq->tool_calls[0]['result']['faq'])->toContain('valet')
            ->and($model->calls[0]['system'])->toContain('Always reply in English')
            ->and(collect($model->calls[0]['tools'])->pluck('name')->all())->toBe(['salon_info', 'list_services', 'check_availability', 'book', 'handoff']);

        $unsure = app(HandleInboundMessage::class)->handle('+97455599991', 'Is the keratin safe while pregnant?', 'wamid.F2');
        expect($unsure->conversation->status)->toBe('handoff')->and($unsure->conversation->handoff_reason)->toBe('low_confidence');

        // Owner switch off → logged for the inbox, no AI, no reply.
        AssistantSettings::put(['enabled' => false]);
        $off = app(HandleInboundMessage::class)->handle('+97455599992', 'Hello?', 'wamid.F3');
        expect($off->direction)->toBe('in')->and($off->conversation->messages()->count())->toBe(1);

        // Outside the AI's hours (salon time 09:00, hours 12:00–22:00) → no reply.
        AssistantSettings::put(['enabled' => true, 'hours_from' => '12:00', 'hours_to' => '22:00']);
        $early = app(HandleInboundMessage::class)->handle('+97455599993', 'Hello?', 'wamid.F4');
        expect($early->direction)->toBe('in');
    });
    expect(count($model->calls))->toBe(3);
});

it('lets the team read and reply from the dashboard inbox', function () {
    $s = receptionistSalon(enabled: false);
    asTenant($s->tenant, fn () => app(HandleInboundMessage::class)->handle('+97455500123', 'Can I change my booking?', 'wamid.I1', 'Reem'));
    $owner = $s->tenant->owner;

    $this->actingAs($owner)->get(tenantUrl($s->tenant, 'dashboard/inbox'))->assertOk()->assertSee('Reem');
    foreach (['dashboard/settings/receptionist', 'dashboard/ai-studio/content'] as $path) {
        $this->get(tenantUrl($s->tenant, $path))->assertOk();
    }

    asTenant($s->tenant, function () {
        Filament::setCurrentPanel('dashboard');
        $id = Conversation::sole()->id;
        Livewire::test(AssistantInbox::class)->call('open', $id)->set('reply', 'Of course! Which day suits you?')->call('send')->assertHasNoErrors();
        $c = Conversation::with('messages')->find($id);
        expect($c->status)->toBe('handoff')->and($c->unread_count)->toBe(0)->and($c->messages->last()->author)->toBe('staff');
        Livewire::test(AssistantInbox::class)->call('open', $id)->call('resumeAi');
        expect($c->fresh()->status)->toBe('bot');
    });
    expect(FakeChannel::sentTo('whatsapp', '+97455500123')[0]['message']->body)->toBe('Of course! Which day suits you?');

    $stylist = tenantUser($s->tenant, 'stylist', [$s->branch->id]);
    $this->flushSession();
    $this->actingAs($stylist)->get(tenantUrl($s->tenant, 'dashboard/inbox'))->assertForbidden();
});

it('writes bilingual content with the content assistant and charges credits', function () {
    aiProviders();
    $s = insightsSalon();
    aiCredits($s->tenant, 5);
    $this->actingAs($s->tenant->owner);
    Http::fake(['api.anthropic.com/*' => Http::response(claudeSays(['en' => 'A silken cut that moves with you.', 'ar' => 'قصة حريرية تتحرك معك.']))]);

    $result = asTenant($s->tenant, fn () => app(GenerateContent::class)->handle('service_description', 'signature cut', 'luxury', $s->services['cut']));
    expect($result)->toBe(['en' => 'A silken cut that moves with you.', 'ar' => 'قصة حريرية تتحرك معك.', 'credits' => 1])
        ->and(AIWallet::for($s->tenant)->balance)->toBe(4);
    Http::assertSent(fn ($r) => str_contains($r->data()['messages'][0]['content'][0]['text'], 'service description'));

    // No credits → a clear error instead of a silent failure.
    $empty = insightsSalon();
    asTenant($empty->tenant, fn () => expect(fn () => app(GenerateContent::class)->handle('caption', 'eid'))->toThrow(DomainException::class));
});
