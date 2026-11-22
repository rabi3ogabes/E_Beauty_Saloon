<?php

namespace App\Domain\CRM\Actions;

use App\Domain\CRM\Models\Client;

/** Marketing consents per channel; each change is timestamped (client_consents.updated_at) with its source. */
class SaveClientConsents
{
    /** @param  array<string, bool>  $consents  channel => granted */
    public function handle(Client $client, array $consents, string $source = 'dashboard'): void
    {
        foreach (Client::CHANNELS as $channel) {
            if (! array_key_exists($channel, $consents)) {
                continue;
            }
            $granted = (bool) $consents[$channel];
            $current = $client->consents()->where('channel', $channel)->first();
            if ($current && $current->granted === $granted) {
                continue; // keep the original timestamp
            }
            $client->setConsent($channel, $granted, $source, request()?->ip());
        }
    }
}
