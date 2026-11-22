<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Channels\MetaWhatsAppChannel;
use App\Domain\Marketing\Models\PlatformMessageChannel;
use App\Domain\Marketing\Models\TenantChannelSetting;
use App\Domain\Marketing\Models\WhatsappTemplate;
use App\Support\Exceptions\DomainException;

/** "Sync approval status": pulls template statuses from the WhatsApp Business Account (Graph API). */
class SyncWhatsAppTemplates
{
    /** @return array{0: string, 1: string} [waba_id, token] */
    public static function credentials(): array
    {
        $setting = TenantChannelSetting::where('channel', 'whatsapp')->first();
        $source = $setting?->isShared() ? PlatformMessageChannel::where('channel', 'whatsapp')->first() : $setting;
        $waba = $source?->credential('waba_id');
        $token = $source?->credential('token');
        if (! $waba || ! $token) {
            throw new DomainException(__('marketing.templates.errors.no_credentials'));
        }

        return [$waba, $token];
    }

    /** @return int number of templates updated */
    public function handle(): int
    {
        [$waba, $token] = self::credentials();
        try {
            $remote = MetaWhatsAppChannel::fetchTemplates($waba, $token);
        } catch (\Throwable $e) {
            throw new DomainException(__('marketing.templates.errors.sync_failed', ['error' => mb_substr($e->getMessage(), 0, 120)]));
        }

        $updated = 0;
        foreach (WhatsappTemplate::all() as $template) {
            $match = $remote[$template->name.'|'.$template->language] ?? null;
            if (! $match) {
                continue;
            }
            $template->update([
                'status' => in_array($match['status'], WhatsappTemplate::STATUSES, true) ? $match['status'] : 'pending',
                'provider_template_id' => $match['id'],
                'rejection_reason' => $match['reason'],
                'synced_at' => now(),
            ]);
            $updated++;
        }

        return $updated;
    }
}
