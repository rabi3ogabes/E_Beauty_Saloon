<?php

namespace App\Domain\AIStudio\Providers;

use App\Domain\AIStudio\DTOs\ImageRequest;
use App\Domain\AIStudio\DTOs\ImageResult;
use App\Domain\AIStudio\Exceptions\ProviderException;

/**
 * OpenRouter: one key for many vendors. Pixels go through its Images API (POST {base}/images, the client
 * photo + the look's reference as input_references); text/vision through its OpenAI-style chat completions.
 * The image model may be a comma-separated list ("google/gemini-3-pro-image, openai/gpt-5-image-mini"): each is
 * tried in order on the same key, so a model that fails on one photo hands it to the next before the client sees an error.
 */
class OpenRouterDriver extends AbstractDriver
{
    public function editImage(ImageRequest $request): ImageResult
    {
        $models = array_values(array_filter(array_map('trim', explode(',', (string) $this->provider->model('image')))));
        $last = null;
        foreach ($models as $model) {
            try {
                return $this->editWith($model, $request);
            } catch (ProviderException $e) {
                // Bad key, no credits or key limit: another model on the same key would fail the same way.
                if (preg_match('/HTTP 40[123]\b/', $e->getMessage())) {
                    throw $e;
                }
                $last = $e;
            }
        }

        throw $last ?? new ProviderException('openrouter: no image model configured');
    }

    protected function editWith(string $model, ImageRequest $request): ImageResult
    {
        $references = [];
        if ($request->image !== null) {
            $references[] = $this->dataUrl($request->image, $request->mime);
        }
        if ($request->reference !== null) {
            $references[] = $this->dataUrl($request->reference, 'image/jpeg');
        }

        $payload = ['model' => $model, 'prompt' => $request->prompt, 'n' => 1];
        if ($references) {
            $payload['input_references'] = array_map(fn ($url) => ['type' => 'image_url', 'image_url' => ['url' => $url]], $references);
        } else {
            $payload['aspect_ratio'] = $request->aspect;
        }

        $json = $this->send(fn ($http) => $this->auth($http)->post($this->provider->baseUrl().'/images', $payload))->json() ?? [];
        $image = data_get($json, 'data.0', []);

        if (! empty($image['b64_json'])) {
            $result = $this->decodeImage($image['b64_json'], $image['media_type'] ?? 'image/png');

            return new ImageResult($result->image, $result->mime, model: $model);
        }
        if (! empty($image['url'])) {
            $download = $this->send(fn ($http) => $http->get($image['url']));

            return new ImageResult($download->body(), $image['media_type'] ?? ($download->header('Content-Type') ?: 'image/png'), model: $model);
        }

        throw new ProviderException('openrouter: '.$model.' returned no image');
    }

    public function generateImage(ImageRequest $request): ImageResult
    {
        return $this->editImage(new ImageRequest($request->prompt, null, aspect: $request->aspect));
    }

    protected function complete(string $prompt, ?string $image = null, string $mime = 'image/jpeg'): string
    {
        $content = [['type' => 'text', 'text' => $prompt]];
        if ($image !== null) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $this->dataUrl($image, $mime)]];
        }

        $json = $this->send(fn ($http) => $this->auth($http)->post($this->provider->baseUrl().'/chat/completions', [
            'model' => $this->provider->model('text'),
            'messages' => [['role' => 'user', 'content' => $content]],
        ]))->json() ?? [];

        $text = data_get($json, 'choices.0.message.content');
        if (is_array($text)) {
            $text = collect($text)->where('type', 'text')->pluck('text')->implode("\n");
        }

        return filled($text) ? (string) $text : throw new ProviderException('openrouter: empty response');
    }

    protected function auth($http)
    {
        return $http->withToken((string) $this->provider->key())->withHeaders(['X-Title' => (string) config('app.name')]);
    }

    protected function dataUrl(string $bytes, string $mime): string
    {
        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
