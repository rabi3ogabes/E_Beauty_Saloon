<?php

namespace App\Domain\Marketing\Channels;

use App\Domain\Messaging\Contracts\MessageChannel;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\DTOs\SendResult;
use Illuminate\Support\Facades\Http;

/**
 * Meta WhatsApp Cloud API (official). Template messages (outside the 24h window) with an optional
 * image header (campaign banner), body variables and URL button suffixes; free-form text/image inside the session.
 */
class MetaWhatsAppChannel implements MessageChannel
{
    public const GRAPH = 'https://graph.facebook.com';

    public function __construct(
        protected string $phoneNumberId,
        protected string $token,
        protected string $version = 'v21.0',
    ) {}

    public function channel(): string
    {
        return 'whatsapp';
    }

    public function send(OutboundMessage $message): SendResult
    {
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => ltrim($message->to, '+')];

        if ($message->template) {
            $payload['type'] = 'template';
            $payload['template'] = ['name' => $message->template, 'language' => ['code' => $message->locale ?? 'en'], 'components' => $this->components($message)];
        } elseif ($message->mediaUrl) {
            $payload['type'] = 'image';
            $payload['image'] = ['link' => $message->mediaUrl, 'caption' => $message->body];
        } else {
            $payload['type'] = 'text';
            $payload['text'] = ['preview_url' => true, 'body' => $message->body];
        }

        try {
            $response = Http::withToken($this->token)->acceptJson()->timeout(15)
                ->post(self::GRAPH."/{$this->version}/{$this->phoneNumberId}/messages", $payload);
        } catch (\Throwable $e) {
            return new SendResult(false, null, $e->getMessage());
        }

        if ($response->successful() && ($id = $response->json('messages.0.id'))) {
            return new SendResult(true, $id);
        }

        return new SendResult(false, null, (string) ($response->json('error.message') ?? 'HTTP '.$response->status()));
    }

    protected function components(OutboundMessage $message): array
    {
        $components = [];
        if ($message->mediaUrl) {
            $components[] = ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => $message->mediaUrl]]]];
        }
        if ($message->variables) {
            $components[] = ['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($message->variables))];
        }
        foreach (array_values($message->buttons) as $index => $button) {
            if (($button['type'] ?? null) === 'url' && isset($button['param'])) {
                $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => (string) $index, 'parameters' => [['type' => 'text', 'text' => (string) $button['param']]]];
            }
        }

        return $components;
    }

    /**
     * Fetch template statuses from the WhatsApp Business Account.
     *
     * @return array<string, array{status: string, id: string, language: string, reason: ?string}> keyed by "name|language"
     */
    public static function fetchTemplates(string $wabaId, string $token, string $version = 'v21.0'): array
    {
        $out = [];
        $url = self::GRAPH."/{$version}/{$wabaId}/message_templates";
        $query = ['fields' => 'name,status,language,id,rejected_reason', 'limit' => 200];
        for ($page = 0; $url && $page < 10; $page++) {
            $response = Http::withToken($token)->acceptJson()->timeout(20)->get($url, $query)->throw();
            foreach ($response->json('data', []) as $t) {
                $out[$t['name'].'|'.$t['language']] = ['status' => strtolower($t['status']), 'id' => (string) $t['id'], 'language' => $t['language'], 'reason' => $t['rejected_reason'] ?? null];
            }
            $url = $response->json('paging.next');
            $query = [];
        }

        return $out;
    }

    /** Submit a template for approval. Returns the provider id. */
    public static function submitTemplate(string $wabaId, string $token, array $template, string $version = 'v21.0'): ?string
    {
        return Http::withToken($token)->acceptJson()->timeout(20)
            ->post(self::GRAPH."/{$version}/{$wabaId}/message_templates", $template)->throw()->json('id');
    }
}
