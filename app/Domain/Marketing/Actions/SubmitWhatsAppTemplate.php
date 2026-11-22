<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Channels\MetaWhatsAppChannel;
use App\Domain\Marketing\Models\WhatsappTemplate;
use App\Support\Exceptions\DomainException;

/** Submits a draft template (body, optional media/text header, footer, buttons) to Meta for approval. */
class SubmitWhatsAppTemplate
{
    public function handle(WhatsappTemplate $template): WhatsappTemplate
    {
        [$waba, $token] = SyncWhatsAppTemplates::credentials();

        $components = [];
        if ($template->header_type === 'text' && $template->header_text) {
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $template->header_text];
        } elseif (in_array($template->header_type, ['image', 'video', 'document'], true)) {
            $components[] = ['type' => 'HEADER', 'format' => strtoupper($template->header_type), 'example' => ['header_handle' => [$template->header_media_url]]];
        }
        $body = ['type' => 'BODY', 'text' => $template->body];
        if ($count = $template->placeholderCount()) {
            $body['example'] = ['body_text' => [array_slice(['Noura', 'https://example.com/c/abc', tenant()?->name ?? 'Salon', 'x', 'y'], 0, $count)]];
        }
        $components[] = $body;
        if ($template->footer) {
            $components[] = ['type' => 'FOOTER', 'text' => $template->footer];
        }
        if ($template->buttons) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => collect($template->buttons)->map(fn ($b) => match ($b['type'] ?? 'url') {
                'phone' => ['type' => 'PHONE_NUMBER', 'text' => $b['text'], 'phone_number' => $b['phone'] ?? ''],
                'quick_reply' => ['type' => 'QUICK_REPLY', 'text' => $b['text']],
                default => array_filter(['type' => 'URL', 'text' => $b['text'], 'url' => $b['url'] ?? '', 'example' => str_contains((string) ($b['url'] ?? ''), '{{1}}') ? ['abc123'] : null]),
            })->values()->all()];
        }

        try {
            $id = MetaWhatsAppChannel::submitTemplate($waba, $token, [
                'name' => $template->name, 'language' => $template->language, 'category' => $template->category, 'components' => $components,
            ]);
        } catch (\Throwable $e) {
            throw new DomainException(__('marketing.templates.errors.submit_failed', ['error' => mb_substr($e->getMessage(), 0, 160)]));
        }
        $template->update(['status' => 'pending', 'provider_template_id' => $id, 'synced_at' => now()]);

        return $template;
    }
}
