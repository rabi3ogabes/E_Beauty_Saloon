<?php

namespace App\Domain\Marketing\Channels;

use App\Domain\Messaging\DTOs\OutboundMessage;

/** Plain-text rendering of a channel-agnostic message for SMS gateways. */
final class SmsText
{
    public static function from(OutboundMessage $message): string
    {
        $text = trim(html_entity_decode(strip_tags((string) $message->body), ENT_QUOTES));
        foreach ($message->buttons as $button) {
            if (! empty($button['url']) && ! str_contains($text, $button['url'])) {
                $text .= "\n".$button['url'];
            }
        }

        return $text;
    }
}
