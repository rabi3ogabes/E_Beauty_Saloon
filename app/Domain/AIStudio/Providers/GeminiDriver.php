<?php

namespace App\Domain\AIStudio\Providers;

use App\Domain\AIStudio\DTOs\ImageRequest;
use App\Domain\AIStudio\DTOs\ImageResult;
use App\Domain\AIStudio\Exceptions\ProviderException;

/**
 * Google Gemini via generativelanguage.googleapis.com — the "Nano Banana" image model for edits,
 * a Gemini text model for analysis. Both use models/{model}:generateContent.
 */
class GeminiDriver extends AbstractDriver
{
    public function editImage(ImageRequest $request): ImageResult
    {
        $parts = [['text' => $request->prompt]];
        if ($request->image !== null) {
            $parts[] = ['inline_data' => ['mime_type' => $request->mime, 'data' => base64_encode($request->image)]];
        }
        if ($request->reference !== null) {
            $parts[] = ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($request->reference)]];
        }

        $json = $this->generate($this->provider->model('image'), $parts, ['responseModalities' => ['IMAGE']]);
        foreach (data_get($json, 'candidates.0.content.parts', []) as $part) {
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if ($inline && ! empty($inline['data'])) {
                return $this->decodeImage($inline['data'], $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png');
            }
        }

        throw new ProviderException('gemini: no image returned');
    }

    public function generateImage(ImageRequest $request): ImageResult
    {
        return $this->editImage(new ImageRequest($request->prompt.' (aspect ratio '.$request->aspect.')', null));
    }

    protected function complete(string $prompt, ?string $image = null, string $mime = 'image/jpeg'): string
    {
        $parts = [['text' => $prompt]];
        if ($image !== null) {
            $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($image)]];
        }
        $json = $this->generate($this->provider->model('text'), $parts);
        $text = collect(data_get($json, 'candidates.0.content.parts', []))->pluck('text')->filter()->implode("\n");

        return $text !== '' ? $text : throw new ProviderException('gemini: empty response');
    }

    protected function generate(?string $model, array $parts, array $config = []): array
    {
        $body = ['contents' => [['role' => 'user', 'parts' => $parts]]];
        if ($config) {
            $body['generationConfig'] = $config;
        }

        return $this->send(fn ($http) => $http->withHeaders(['x-goog-api-key' => (string) $this->provider->key()])
            ->post($this->provider->baseUrl().'/models/'.$model.':generateContent', $body))->json() ?? [];
    }
}
