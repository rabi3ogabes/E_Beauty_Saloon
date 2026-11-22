<?php

namespace App\Domain\Assistant\Actions;

use App\Domain\Assistant\Contracts\AssistantModel;
use App\Domain\Assistant\Models\Conversation;
use App\Domain\Assistant\Models\Message;
use App\Domain\Assistant\Support\AssistantSettings;
use App\Domain\Assistant\Tools\ReceptionistTools;
use App\Domain\CRM\Models\Client;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\MessagingManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * WhatsApp AI receptionist (runs inside the tenant context). Every inbound message is logged to the conversation
 * (dashboard inbox). The AI answers only when the owner switched it on and inside its hours, and never once the
 * conversation was handed to a human. Handoff happens on request ("agent"/"موظف"…), on low confidence, when the
 * model calls the handoff tool, or when no AI is available. Replies go out in the client's language.
 */
class HandleInboundMessage
{
    public const MAX_STEPS = 5;

    public function __construct(protected AssistantModel $model, protected ReceptionistTools $tools, protected MessagingManager $messaging) {}

    public function handle(string $phone, ?string $text, ?string $providerMessageId = null, ?string $profileName = null): ?Message
    {
        $phone = '+'.ltrim($phone, '+');
        $text = trim((string) $text);
        if ($text === '') {
            $text = '['.__('insights.assistant.non_text').']';
        }

        $conversation = Conversation::firstOrCreate(['channel' => 'whatsapp', 'phone' => $phone], ['contact_name' => $profileName]);
        $client = $conversation->client ?? Client::query()->where('phone', $phone)->first();
        $locale = self::detectLocale($text, $client?->locale ?? $conversation->locale);
        $conversation->fill(['client_id' => $client?->id ?? $conversation->client_id, 'locale' => $locale, 'contact_name' => $conversation->contact_name ?: $profileName]);

        try {
            $inbound = $conversation->messages()->create(['direction' => 'in', 'author' => 'client', 'body' => mb_substr($text, 0, 4000), 'provider_message_id' => $providerMessageId]);
        } catch (UniqueConstraintViolationException) {
            return null; // webhook retry: already handled
        }
        $conversation->forceFill(['unread_count' => $conversation->unread_count + 1, 'last_message_at' => now()])->save();

        if ($conversation->isHandedOff() || ! AssistantSettings::onDuty()) {
            return $inbound;
        }
        if ($this->asksForHuman($text)) {
            return $this->handoff($conversation, 'requested');
        }

        return $this->converse($conversation->fresh('client'));
    }

    protected function converse(Conversation $conversation): ?Message
    {
        $history = $conversation->messages()->latest('id')->limit(12)->get()->reverse()->values()
            ->map(fn (Message $m) => ['role' => $m->author === 'client' ? 'client' : 'assistant', 'content' => $m->body])->all();
        $log = [];
        $minConfidence = (float) AssistantSettings::get('min_confidence', 0.5);

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $turn = $this->model->next($this->system($conversation), $history, $this->tools->definitions());
            if (! $turn) {
                return $this->handoff($conversation, 'ai_unavailable', $log);
            }
            if ($turn->wantsTools()) {
                foreach ($turn->toolCalls as $call) {
                    $result = $this->tools->call($call['name'], $call['arguments'], $conversation);
                    $log[] = ['name' => $call['name'], 'arguments' => $call['arguments'], 'result' => $result];
                    if (! empty($result['handoff'])) {
                        return $this->handoff($conversation, 'tool', $log);
                    }
                    $history[] = ['role' => 'tool', 'content' => $call['name'].' → '.json_encode($result, JSON_UNESCAPED_UNICODE)];
                }

                continue;
            }
            if (! $turn->reply || $turn->confidence < $minConfidence) {
                return $this->handoff($conversation, 'low_confidence', $log, $turn->confidence);
            }

            return $this->send($conversation, $turn->reply, 'bot', $log, $turn->confidence);
        }

        return $this->handoff($conversation, 'low_confidence', $log);
    }

    protected function system(Conversation $c): string
    {
        $language = $c->locale === 'ar' ? 'Arabic (warm, Gulf-friendly)' : 'English';

        return 'You are the WhatsApp receptionist of "'.tenant()->name.'", a luxury beauty salon. Tone: '.AssistantSettings::get('tone').'. '
            ."Always reply in {$language}, in at most 3 short sentences. Today is ".now(tenant()->timezone)->toDateString().' (salon time '.tenant()->timezone.'). '
            .'Use tools for facts: salon_info for hours/addresses/FAQ, list_services for prices, check_availability before offering times, book only a time the client chose. '
            .'Never invent prices, times or bookings. For complaints, medical or allergy questions, or anything you cannot do, call handoff. '
            .($c->client ? 'The client is a known client named '.$c->client->firstName().'.' : 'The client is new; ask for their name before booking.');
    }

    public function handoff(Conversation $conversation, string $reason, array $log = [], ?float $confidence = null): Message
    {
        $conversation->update(['status' => 'handoff', 'handoff_reason' => $reason]);

        return $this->send($conversation, __('insights.assistant.handoff_reply', ['salon' => tenant()->name], $conversation->locale), 'bot', $log, $confidence, ['handoff' => $reason]);
    }

    protected function send(Conversation $conversation, string $body, string $author, array $log = [], ?float $confidence = null, array $meta = []): Message
    {
        $result = $this->messaging->send('whatsapp', new OutboundMessage(
            to: $conversation->phone, body: $body, locale: $conversation->locale,
            meta: ['type' => 'assistant_reply', 'conversation_id' => $conversation->id] + $meta,
        ));

        $message = $conversation->messages()->create([
            'direction' => 'out', 'author' => $author, 'body' => $body, 'tool_calls' => $log ?: null,
            'confidence' => $confidence, 'provider_message_id' => $result->ok ? $result->providerMessageId : null,
        ]);
        $conversation->forceFill(['last_message_at' => now()])->save();

        return $message;
    }

    protected function asksForHuman(string $text): bool
    {
        $t = mb_strtolower($text);
        foreach ((array) AssistantSettings::get('handoff_keywords', []) as $keyword) {
            $k = mb_strtolower(trim((string) $keyword));
            if ($k !== '' && preg_match('/(^|[^\p{L}])'.preg_quote($k, '/').'($|[^\p{L}])/u', $t)) {
                return true;
            }
        }

        return false;
    }

    public static function detectLocale(string $text, ?string $fallback = 'en'): string
    {
        if (preg_match('/\p{Arabic}/u', $text)) {
            return 'ar';
        }
        if (preg_match('/\p{Latin}{2,}/u', $text)) {
            return 'en';
        }

        return in_array($fallback, ['ar', 'en'], true) ? $fallback : 'en';
    }
}
