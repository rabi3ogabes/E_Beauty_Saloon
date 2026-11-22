<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Events\WhatsAppMessageReceived;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Domain\Marketing\Models\PlatformMessageChannel;
use App\Domain\Marketing\Models\TenantChannelSetting;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Meta WhatsApp Cloud webhook: delivery/read/failed statuses update campaign_messages; inbound "STOP"
 * messages withdraw WhatsApp consent. The tenant is found by metadata.phone_number_id (salon's own number)
 * or, for the platform-shared number, by the provider message id.
 */
class ProcessWhatsAppWebhook
{
    public function __construct(protected TenantContext $context, protected Unsubscribe $unsubscribe) {}

    /** X-Hub-Signature-256 = "sha256=" . HMAC_SHA256(app_secret, raw body). Secrets come from matching tenant(s) or the platform app. */
    public function verifySignature(string $rawBody, ?string $header): bool
    {
        if (! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }
        $phoneNumberIds = collect(data_get(json_decode($rawBody, true) ?: [], 'entry', []))
            ->flatMap(fn ($e) => collect($e['changes'] ?? [])->pluck('value.metadata.phone_number_id'))->filter()->unique();

        $secrets = TenantChannelSetting::withoutGlobalScopes()->where('channel', 'whatsapp')->whereIn('external_id', $phoneNumberIds->all() ?: ['-'])
            ->get()->map(fn ($s) => $s->credential('app_secret'));
        $platform = PlatformMessageChannel::where('channel', 'whatsapp')->first();
        $secrets->push($platform?->credential('app_secret'));

        foreach ($secrets->filter()->unique() as $secret) {
            if (hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $header)) {
                return true;
            }
        }

        return false;
    }

    public function handle(array $payload): array
    {
        $stats = ['statuses' => 0, 'unsubscribed' => 0];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = data_get($value, 'metadata.phone_number_id');
                $tenantId = $phoneNumberId ? TenantChannelSetting::withoutGlobalScopes()->where('channel', 'whatsapp')->where('external_id', $phoneNumberId)->value('tenant_id') : null;

                foreach ($value['statuses'] ?? [] as $status) {
                    $message = CampaignMessage::withoutGlobalScopes()->where('provider_message_id', $status['id'] ?? '-')->first();
                    if (! $message) {
                        continue;
                    }
                    $this->context->run(Tenant::find($message->tenant_id), function () use ($message, $status) {
                        $at = isset($status['timestamp']) ? now()->setTimestamp((int) $status['timestamp']) : now();
                        $state = $status['status'] ?? null;
                        if (in_array($state, ['sent', 'delivered', 'read'], true)) {
                            $message->advanceTo($state, $at);
                        } elseif ($state === 'failed') {
                            $message->advanceTo('failed');
                            $message->update(['error' => mb_substr((string) data_get($status, 'errors.0.title', 'failed'), 0, 250)]);
                        }
                    });
                    $stats['statuses']++;
                }

                if ($tenantId) {
                    foreach ($value['messages'] ?? [] as $inbound) {
                        $text = data_get($inbound, 'text.body') ?? data_get($inbound, 'button.text') ?? data_get($inbound, 'interactive.button_reply.title');
                        $applied = $this->context->run(Tenant::find($tenantId), fn () => $this->unsubscribe->fromInbound('+'.ltrim((string) ($inbound['from'] ?? ''), '+'), $text, 'whatsapp'));
                        $stats['unsubscribed'] += $applied ? 1 : 0;
                        // Hook for other modules (AI receptionist): every other inbound message.
                        if (! $applied) {
                            WhatsAppMessageReceived::dispatch((int) $tenantId, '+'.ltrim((string) ($inbound['from'] ?? ''), '+'), $text,
                                $inbound['id'] ?? null, data_get($value, 'contacts.0.profile.name'), (string) ($inbound['type'] ?? 'text'));
                        }
                    }
                }
            }
        }

        return $stats;
    }
}
