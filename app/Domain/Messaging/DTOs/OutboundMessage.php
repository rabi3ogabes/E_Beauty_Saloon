<?php

namespace App\Domain\Messaging\DTOs;

/**
 * Channel-agnostic message. WhatsApp uses $template (+ $variables) outside the 24h session;
 * email uses $subject/$body (HTML allowed); SMS/push use $body.
 */
final class OutboundMessage
{
    public function __construct(
        public readonly string $to,               // E.164 phone, email or push token
        public readonly string $body,
        public readonly ?string $subject = null,
        public readonly ?string $template = null, // WhatsApp template name
        public readonly array $variables = [],
        public readonly ?string $mediaUrl = null, // banner image
        public readonly array $buttons = [],      // [['type'=>'url','text'=>'Book now','url'=>...]]
        public readonly ?string $locale = null,
        public readonly array $meta = [],         // campaign_id, booking_id ... for tracking
    ) {}
}
