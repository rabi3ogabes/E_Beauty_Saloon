<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Models\AIConsent;
use App\Domain\CRM\Models\Client;

/** Explicit consent for AI photo processing (+ 18+ age confirmation). Also mirrored to client_consents.ai_photos. */
class RecordConsent
{
    public function handle(?Client $client, ?string $device, bool $isAdult, ?string $ip = null, ?string $userAgent = null): AIConsent
    {
        $consent = AIConsent::create([
            'client_id' => $client?->id,
            'device_fingerprint' => $device,
            'version' => config('ai.consent_version'),
            'age_confirmed' => $isAdult,
            'is_minor' => ! $isAdult,
            'ip' => $ip,
            'user_agent' => mb_substr((string) $userAgent, 0, 250),
            'accepted_at' => now(),
        ]);

        $client?->setConsent('ai_photos', true, 'ai_studio', $ip);

        return $consent;
    }

    /** Latest valid (current version, not withdrawn) consent for a client or device. */
    public static function current(?Client $client, ?string $device): ?AIConsent
    {
        if (! $client && ! $device) {
            return null;
        }

        return AIConsent::query()
            ->where('version', config('ai.consent_version'))
            ->whereNull('withdrawn_at')
            ->where(fn ($q) => $client
                ? $q->where('client_id', $client->id)->when($device, fn ($w) => $w->orWhere(fn ($d) => $d->whereNull('client_id')->where('device_fingerprint', $device)))
                : $q->whereNull('client_id')->where('device_fingerprint', $device))
            ->latest('id')
            ->first();
    }

    public static function withdraw(?Client $client, ?string $device): void
    {
        AIConsent::query()->whereNull('withdrawn_at')
            ->where(fn ($q) => $client ? $q->where('client_id', $client->id) : $q->where('device_fingerprint', $device))
            ->update(['withdrawn_at' => now()]);
        $client?->setConsent('ai_photos', false, 'ai_studio');
    }
}
