<?php

namespace App\Domain\Marketing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hook (Phase 9): an inbound WhatsApp message for a salon's own number that was NOT a STOP/unsubscribe keyword.
 * Dispatched by ProcessWhatsAppWebhook so other modules (e.g. the AI receptionist) can react without changing
 * marketing code. Plain scalars only, safe to queue.
 */
class WhatsAppMessageReceived
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public string $from,          // E.164 with leading +
        public ?string $text,
        public ?string $messageId,    // wamid.* — use for idempotency
        public ?string $profileName = null,
        public string $type = 'text',
    ) {}
}
