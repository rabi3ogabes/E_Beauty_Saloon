<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\CRM\Models\Client;

/**
 * Withdraws marketing consent: from the unsubscribe link (/u/{token}) or an inbound STOP keyword.
 */
class Unsubscribe
{
    /** Inbound keywords (case-insensitive, trimmed) that unsubscribe the sender. */
    public const KEYWORDS = ['stop', 'unsubscribe', 'cancel', 'end', 'quit', 'إيقاف', 'ايقاف', 'الغاء', 'إلغاء', 'توقف'];

    public static function isStopKeyword(?string $text): bool
    {
        return in_array(mb_strtolower(trim((string) $text)), self::KEYWORDS, true);
    }

    /** @param list<string>|null $channels null = every marketing channel */
    public function handle(Client $client, ?array $channels = null, string $source = 'unsubscribe_link', ?string $ip = null): void
    {
        foreach ($channels ?? Client::CHANNELS as $channel) {
            $client->setConsent($channel, false, $source, $ip);
        }
        activity()->performedOn($client)->withProperties(['channels' => $channels ?? Client::CHANNELS, 'source' => $source])->log('client.unsubscribed');
    }

    /** Inbound message from a phone: returns true when it was a STOP request that was applied. */
    public function fromInbound(string $phone, ?string $text, string $channel): bool
    {
        if (! self::isStopKeyword($text)) {
            return false;
        }
        $client = Client::where('phone', Client::normalizePhone($phone))->first();
        if (! $client) {
            return false;
        }
        $this->handle($client, [$channel], 'keyword_stop');

        return true;
    }
}
