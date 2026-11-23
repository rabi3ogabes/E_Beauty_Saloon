<?php

namespace App\Domain\Assistant\Actions;

use App\Domain\Assistant\Models\Conversation;
use App\Domain\Assistant\Models\Message;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\MessagingManager;
use App\Support\Exceptions\DomainException;

/** A team member answers from the dashboard inbox. Taking over pauses the AI for this conversation. */
class ReplyAsStaff
{
    public function __construct(protected MessagingManager $messaging) {}

    public function handle(Conversation $conversation, string $body, bool $takeOver = true): Message
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        $body = trim($body);
        if ($body === '') {
            throw new DomainException(__('insights.assistant.empty_reply'));
        }
        $result = $this->messaging->send('whatsapp', new OutboundMessage(
            to: $conversation->phone, body: mb_substr($body, 0, 4000), locale: $conversation->locale,
            meta: ['type' => 'assistant_staff_reply', 'conversation_id' => $conversation->id],
        ));
        if (! $result->ok) {
            throw new DomainException(__('insights.assistant.send_failed'));
        }
        $message = $conversation->messages()->create([
            'direction' => 'out', 'author' => 'staff', 'body' => $body, 'user_id' => auth()->id(), 'provider_message_id' => $result->providerMessageId,
        ]);
        $conversation->forceFill(array_filter([
            'unread_count' => 0, 'last_message_at' => now(),
            'status' => $takeOver ? 'handoff' : null, 'handoff_reason' => $takeOver && ! $conversation->isHandedOff() ? 'staff' : null,
        ], fn ($v) => $v !== null))->save();

        return $message;
    }

    public function resumeAi(Conversation $conversation): void
    {
        $conversation->update(['status' => 'bot', 'handoff_reason' => null]);
    }

    public function markRead(Conversation $conversation): void
    {
        $conversation->forceFill(['unread_count' => 0])->save();
    }
}
