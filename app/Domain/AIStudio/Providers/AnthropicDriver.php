<?php

namespace App\Domain\AIStudio\Providers;

use App\Domain\AIStudio\Exceptions\ProviderException;

/**
 * Claude (vision + text) over the Messages API: POST {base}/messages with x-api-key and anthropic-version.
 * Used for face analysis, recommendations, prompt writing and captions — never for pixels.
 */
class AnthropicDriver extends AbstractDriver
{
    protected function complete(string $prompt, ?string $image = null, string $mime = 'image/jpeg'): string
    {
        $content = [];
        if ($image !== null) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($image)]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $body = [
            'model' => $this->provider->model('text') ?: 'claude-opus-5-5',
            'max_tokens' => 16000,
            'messages' => [['role' => 'user', 'content' => $content]],
        ];
        $headers = [
            'x-api-key' => (string) $this->provider->key(),
            'anthropic-version' => (string) config('ai.providers.anthropic.version', '2023-06-01'),
            'content-type' => 'application/json',
        ];
        // Server-side refusal fallback (opt-out per provider row: options.fallbacks = false).
        if (data_get($this->provider->options, 'fallbacks', true)) {
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
            $body['fallbacks'] = 'default';
        }

        $json = $this->send(fn ($http) => $http->withHeaders($headers)->post($this->provider->baseUrl().'/messages', $body))->json();

        if (($json['stop_reason'] ?? null) === 'refusal') {
            throw new ProviderException('anthropic: request declined ('.data_get($json, 'stop_details.category', 'refusal').')');
        }
        $text = collect($json['content'] ?? [])->where('type', 'text')->pluck('text')->implode("\n");
        if ($text === '') {
            throw new ProviderException('anthropic: empty response');
        }

        return $text;
    }
}
