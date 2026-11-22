<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Domain\Marketing\Models\WhatsappTemplate;
use App\Domain\Marketing\Services\MarketingMessenger;
use App\Domain\Marketing\Services\MessageCredits;
use App\Domain\Marketing\Services\MessageRenderer;
use App\Domain\Marketing\Services\QuietHours;
use App\Domain\Marketing\Support\MarketingUrl;
use App\Domain\Messaging\DTOs\OutboundMessage;

/**
 * Sends one campaign message (one client) through the consented fallback chain.
 * Inside quiet hours the message is deferred (status skipped_quiet_hours + scheduled_for) and picked up
 * by the scheduler when the window ends.
 */
class SendCampaignMessage
{
    public function __construct(
        protected MarketingMessenger $messenger,
        protected MessageRenderer $renderer,
        protected QuietHours $quietHours,
    ) {}

    public function handle(CampaignMessage $message): CampaignMessage
    {
        if (! in_array($message->status, ['queued', 'skipped_quiet_hours'], true)) {
            return $message; // idempotent: already sent / skipped / held
        }
        $campaign = $message->campaign;
        $client = $message->client;
        if (! $campaign || ! $client || $campaign->status === 'cancelled') {
            return $message;
        }

        if ($resume = $this->quietHours->nextAllowed($client)) {
            $message->update(['status' => 'skipped_quiet_hours', 'scheduled_for' => $resume]);

            return $message;
        }

        $variant = $message->variant;
        $content = $variant?->content ?: $campaign->content;
        $templateId = $variant?->whatsapp_template_id ?: $campaign->whatsapp_template_id;
        $locale = $client->locale ?: 'en';
        $link = MarketingUrl::trackedLink($message->token);
        $unsubscribe = MarketingUrl::unsubscribe($message->token);
        $extra = ['link' => $link, 'unsubscribe' => $unsubscribe];
        $body = $this->renderer->render($this->renderer->localized(collect($content)->map(fn ($c) => $c['body'] ?? null)->all(), $locale), $client, $extra);
        $subject = $this->renderer->render($this->renderer->localized(collect($content)->map(fn ($c) => $c['subject'] ?? null)->all(), $locale), $client, $extra) ?: $campaign->name;
        $media = $campaign->mediaUrl();
        $cta = $campaign->cta_label ?: __('marketing.campaigns.default_cta', [], $locale);

        [$channel, $result] = $this->messenger->send($client, $campaign->channels, function (string $channel, string $to) use ($client, $campaign, $message, $body, $subject, $media, $link, $unsubscribe, $cta, $templateId, $locale) {
            $meta = ['campaign_id' => $campaign->id, 'campaign_message_id' => $message->id, 'unsubscribe_url' => $unsubscribe, 'reference' => 'campaign:'.$campaign->id];

            return match ($channel) {
                'whatsapp' => $this->whatsapp($to, $client, $body, $media, $link, $cta, $message->token, $templateId, $locale, $meta),
                'sms' => new OutboundMessage($to, $body."\n".__('marketing.unsubscribe.sms_footer', [], $locale), locale: $locale, meta: $meta),
                'email' => new OutboundMessage($to, nl2br(e($body)).'<p style="margin-top:28px;font-size:12px;color:#8a8478"><a href="'.e($unsubscribe).'" style="color:#8a8478">'.e(__('marketing.unsubscribe.link', [], $locale)).'</a></p>',
                    subject: $subject, mediaUrl: $media, buttons: [['type' => 'url', 'text' => $cta, 'url' => $link]], locale: $locale, meta: $meta),
                'push' => new OutboundMessage($to, $body, subject: $subject, mediaUrl: $media, buttons: [['type' => 'url', 'text' => $cta, 'url' => $link]], locale: $locale, meta: $meta),
                default => null,
            };
        });

        if ($result->ok) {
            $message->update([
                'channel' => $channel, 'status' => 'sent', 'sent_at' => now(), 'provider_message_id' => $result->providerMessageId,
                'cost' => $result->costUnits, 'error' => null, 'scheduled_for' => null,
            ]);
        } else {
            $status = match ($result->error) {
                'no_consent' => 'skipped_no_consent',
                MessageCredits::INSUFFICIENT => 'skipped_credits',
                default => 'failed',
            };
            $message->update(['status' => $status, 'error' => mb_substr((string) $result->error, 0, 250), 'channel' => $channel]);
        }

        return $message;
    }

    protected function whatsapp(string $to, Client $client, string $body, ?string $media, string $link, string $cta, string $token, ?int $templateId, string $locale, array $meta): OutboundMessage
    {
        $template = $templateId ? WhatsappTemplate::find($templateId) : null;
        if ($template) {
            // Prefer the approved translation of the same template in the client's language.
            $template = WhatsappTemplate::where('name', $template->name)->where('language', $locale)->where('status', 'approved')->first() ?? $template;
            $variables = array_slice([$client->firstName(), $link, tenant()?->name], 0, max(0, $template->placeholderCount()));
            $buttons = collect($template->buttons ?? [])->map(fn ($b) => ($b['type'] ?? null) === 'url'
                ? ['type' => 'url', 'text' => $b['text'] ?? $cta, 'url' => $link, 'param' => str_contains((string) ($b['url'] ?? ''), '{{1}}') ? $token : null]
                : $b)->map(fn ($b) => array_filter($b, fn ($v) => $v !== null))->all();

            return new OutboundMessage($to, $body, template: $template->name, variables: $variables, mediaUrl: $media ?? $template->header_media_url,
                buttons: $buttons, locale: $template->language, meta: $meta);
        }

        return new OutboundMessage($to, $body, mediaUrl: $media, buttons: [['type' => 'url', 'text' => $cta, 'url' => $link]], locale: $locale, meta: $meta);
    }
}
