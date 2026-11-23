<?php

namespace App\Domain\AIStudio\Providers;

use App\Domain\AIStudio\DTOs\ImageRequest;
use App\Domain\AIStudio\DTOs\ImageResult;
use App\Domain\AIStudio\Exceptions\ProviderException;

/**
 * Plug in a new vendor without code: JSON over HTTP.
 * options: {"paths":{"image":"/image","text":"/text","status":"/status/{id}"},"auth_header":"Authorization","auth_prefix":"Bearer "}
 * Request: {operation, model, prompt, image_base64?, mime?}. Response: {image_base64, mime} | {text} | {id, status:"pending"}.
 */
class GenericHttpDriver extends AbstractDriver
{
    public function editImage(ImageRequest $request): ImageResult
    {
        return $this->image('edit', $request);
    }

    public function generateImage(ImageRequest $request): ImageResult
    {
        return $this->image('generate', $request);
    }

    public function inpaint(ImageRequest $request): ImageResult
    {
        return $this->image('inpaint', $request);
    }

    public function getStatus(string $externalId): ImageResult
    {
        $path = str_replace('{id}', rawurlencode($externalId), (string) data_get($this->provider->options, 'paths.status', '/status/{id}'));

        return $this->toResult($this->send(fn ($http) => $this->auth($http)->get($this->provider->baseUrl().$path))->json() ?? []);
    }

    protected function image(string $operation, ImageRequest $request): ImageResult
    {
        $payload = ['operation' => $operation, 'model' => $this->provider->model('image'), 'prompt' => $request->prompt, 'aspect' => $request->aspect];
        if ($request->image !== null) {
            $payload['image_base64'] = base64_encode($request->image);
            $payload['mime'] = $request->mime;
        }
        $path = (string) data_get($this->provider->options, 'paths.image', '/image');

        return $this->toResult($this->send(fn ($http) => $this->auth($http)->post($this->provider->baseUrl().$path, $payload))->json() ?? []);
    }

    protected function toResult(array $json): ImageResult
    {
        if (($json['status'] ?? null) === 'pending') {
            return new ImageResult(null, status: 'pending', externalId: (string) ($json['id'] ?? ''));
        }

        return $this->decodeImage($json['image_base64'] ?? null, $json['mime'] ?? 'image/png');
    }

    protected function complete(string $prompt, ?string $image = null, string $mime = 'image/jpeg'): string
    {
        $payload = ['operation' => 'text', 'model' => $this->provider->model('text'), 'prompt' => $prompt];
        if ($image !== null) {
            $payload['image_base64'] = base64_encode($image);
            $payload['mime'] = $mime;
        }
        $path = (string) data_get($this->provider->options, 'paths.text', '/text');
        $text = (string) ($this->send(fn ($http) => $this->auth($http)->post($this->provider->baseUrl().$path, $payload))->json('text') ?? '');

        return $text !== '' ? $text : throw new ProviderException('http: empty response');
    }

    protected function auth($http)
    {
        $header = (string) data_get($this->provider->options, 'auth_header', 'Authorization');
        $prefix = (string) data_get($this->provider->options, 'auth_prefix', 'Bearer ');

        return $this->provider->key() ? $http->withHeaders([$header => $prefix.$this->provider->key()]) : $http;
    }
}
