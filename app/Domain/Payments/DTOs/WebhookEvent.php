<?php

namespace App\Domain\Payments\DTOs;

final class WebhookEvent
{
    /**
     * @param  string  $status  succeeded|failed|refunded|pending
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly string $status,
        public readonly ?string $reference = null,       // provider payment reference
        public readonly ?string $merchantReference = null, // our payment id / idempotency key
        public readonly ?int $amount = null,
        public readonly array $raw = [],
    ) {}
}
