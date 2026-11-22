<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignMessage;

/**
 * /c/{token}: records the click and remembers the campaign in the session for 7 days so the booking
 * created afterwards carries campaign_id (the redirect also appends ?campaign={id} for the Booking module).
 */
class TrackCampaignClick
{
    public const SESSION_KEY = 'marketing.attribution';

    public function handle(CampaignMessage $message): string
    {
        $message->advanceTo('clicked');
        $campaign = $message->campaign;

        session()->put(self::SESSION_KEY, [
            'campaign_id' => $message->campaign_id,
            'client_id' => $message->client_id,
            'message_id' => $message->id,
            'expires_at' => now()->addDays(Campaign::ATTRIBUTION_DAYS)->timestamp,
        ]);

        $target = $campaign?->ctaUrl() ?? '/book';
        $separator = str_contains($target, '?') ? '&' : '?';

        return $target.$separator.http_build_query(['campaign' => $message->campaign_id, 'utm_source' => 'campaign', 'utm_medium' => $message->channel ?? 'message']);
    }

    /**
     * Campaign id remembered for this visitor (inside the attribution window) when the booking is for the
     * recipient who clicked — staff sessions in /dashboard or /pos never attribute.
     */
    public static function rememberedCampaignId(?int $clientId = null): ?int
    {
        if (! app()->bound('session') || ! request()->hasSession() || request()->is('dashboard*', 'pos*', 'admin*', 'livewire*')) {
            return null;
        }
        $data = session(self::SESSION_KEY);
        if (! $data || ($data['expires_at'] ?? 0) < now()->timestamp) {
            return null;
        }
        $visitor = auth('client')->id();
        if ($clientId && (int) $data['client_id'] !== $clientId && $visitor !== $clientId) {
            return null;
        }

        return (int) $data['campaign_id'];
    }
}
