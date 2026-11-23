<?php

namespace App\Domain\AIStudio\Providers;

use App\Domain\AIStudio\DTOs\ImageRequest;
use App\Domain\AIStudio\DTOs\ImageResult;
use App\Domain\AIStudio\Exceptions\ProviderException;

/** OpenAI: images/edits + images/generations for pixels, the Responses API for text/vision. */
class OpenAIDriver extends AbstractDriver
{
    public function editImage(ImageRequest $request): ImageResult
    {
        if ($request->image === null) {
            return $this->generateImage($request);
        }
        $ext = str_contains($request->mime, 'png') ? 'png' : (str_contains($request->mime, 'webp') ? 'webp' : 'jpg');

        $json = $this->send(function ($http) use ($request, $ext) {
            $http = $http->withToken((string) $this->provider->key())->attach('image', $request->image, 'photo.'.$ext, ['Content-Type' => $request->mime]);
            if ($request->mask !== null) {
                $http = $http->attach('mask', $request->mask, 'mask.png', ['Content-Type' => 'image/png']);
            }

            return $http->post($this->provider->baseUrl().'/images/edits', [
                'model' => $this->provider->model('image'),
                'prompt' => $request->prompt,
                'n' => 1,
            ]);
        })->json();

        return $this->decodeImage(data_get($json, 'data.0.b64_json'));
    }

    public function generateImage(ImageRequest $request): ImageResult
    {
        $size = match ($request->aspect) {
            '16:9', '3:2' => '1536x1024',
            '9:16', '2:3', '4:5' => '1024x1536',
            default => '1024x1024',
        };
        $json = $this->send(fn ($http) => $http->withToken((string) $this->provider->key())->post($this->provider->baseUrl().'/images/generations', [
            'model' => $this->provider->model('image'), 'prompt' => $request->prompt, 'size' => $size, 'n' => 1,
        ]))->json();

        return $this->decodeImage(data_get($json, 'data.0.b64_json'));
    }

    protected function complete(string $prompt, ?string $image = null, string $mime = 'image/jpeg'): string
    {
        $content = [['type' => 'input_text', 'text' => $prompt]];
        if ($image !== null) {
            $content[] = ['type' => 'input_image', 'image_url' => 'data:'.$mime.';base64,'.base64_encode($image)];
        }
        $json = $this->send(fn ($http) => $http->withToken((string) $this->provider->key())->post($this->provider->baseUrl().'/responses', [
            'model' => $this->provider->model('text'),
            'input' => [['role' => 'user', 'content' => $content]],
        ]))->json();

        $text = $json['output_text'] ?? collect($json['output'] ?? [])->flatMap(fn ($o) => $o['content'] ?? [])
            ->where('type', 'output_text')->pluck('text')->implode("\n");

        return $text !== '' ? $text : throw new ProviderException('openai: empty response');
    }
}
