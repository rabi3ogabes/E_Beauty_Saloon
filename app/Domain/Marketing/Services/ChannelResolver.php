<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Marketing\Channels\CreditMeteredChannel;
use App\Domain\Marketing\Channels\FcmPushChannel;
use App\Domain\Marketing\Channels\HttpGatewaySmsChannel;
use App\Domain\Marketing\Channels\MetaWhatsAppChannel;
use App\Domain\Marketing\Channels\TenantMailChannel;
use App\Domain\Marketing\Channels\TwilioSmsChannel;
use App\Domain\Marketing\Channels\UnifonicSmsChannel;
use App\Domain\Marketing\Models\PlatformMessageChannel;
use App\Domain\Marketing\Models\TenantChannelSetting;
use App\Domain\Messaging\Contracts\MessageChannel;
use App\Domain\Tenancy\Models\Tenant;

/**
 * Builds the real driver for (tenant, channel): the salon's own credentials, or the platform-shared sender
 * wrapped in CreditMeteredChannel. Returns null when nothing is configured (MessagingManager falls back to log/mail).
 */
class ChannelResolver
{
    public function resolve(?Tenant $tenant, string $channel): ?MessageChannel
    {
        if (! $tenant) {
            return null;
        }
        $setting = TenantChannelSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('channel', $channel)->where('is_active', true)->first();
        if (! $setting) {
            return null;
        }

        if ($setting->isShared()) {
            $platform = PlatformMessageChannel::where('channel', $channel)->where('is_active', true)->first();
            $driver = $platform ? $this->make($channel, (string) $platform->provider, $platform->credentials ?? []) : null;

            return $driver ? new CreditMeteredChannel($driver, $tenant->id, (int) $platform->price_per_message) : null;
        }

        return $this->make($channel, (string) $setting->provider, $setting->credentials ?? []);
    }

    public function make(string $channel, string $provider, array $c): ?MessageChannel
    {
        $filled = fn (string ...$keys) => collect($keys)->every(fn ($k) => filled($c[$k] ?? null));

        return match ([$channel, $provider]) {
            ['whatsapp', 'meta'] => $filled('phone_number_id', 'token') ? new MetaWhatsAppChannel($c['phone_number_id'], $c['token'], $c['graph_version'] ?? 'v21.0') : null,
            ['sms', 'twilio'] => $filled('account_sid', 'auth_token', 'from') ? new TwilioSmsChannel($c['account_sid'], $c['auth_token'], $c['from']) : null,
            ['sms', 'unifonic'] => $filled('app_sid', 'sender_id') ? new UnifonicSmsChannel($c['app_sid'], $c['sender_id']) : null,
            ['sms', 'http'] => $filled('url') ? new HttpGatewaySmsChannel(array_merge($c, [
                'params' => is_string($c['params'] ?? null) ? (json_decode($c['params'], true) ?: []) : ($c['params'] ?? []),
                'headers' => is_string($c['headers'] ?? null) ? (json_decode($c['headers'], true) ?: []) : ($c['headers'] ?? []),
            ])) : null,
            ['email', 'mail'], ['email', 'ses'] => new TenantMailChannel(array_merge($c, ['mailer' => $provider === 'ses' ? 'ses' : ($c['mailer'] ?? null)])),
            ['push', 'fcm'] => $filled('project_id', 'service_account') ? new FcmPushChannel($c['project_id'], is_array($c['service_account']) ? $c['service_account'] : (json_decode($c['service_account'], true) ?: [])) : null,
            default => null,
        };
    }
}
