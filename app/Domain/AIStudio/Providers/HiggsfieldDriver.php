<?php

namespace App\Domain\AIStudio\Providers;

use App\Domain\AIStudio\Contracts\AIVideoProvider;
use App\Domain\AIStudio\DTOs\ImageRequest;
use App\Domain\AIStudio\DTOs\ImageResult;
use App\Domain\AIStudio\Exceptions\ProviderException;
use App\Domain\AIStudio\Exceptions\ProviderTimeoutException;
use Illuminate\Support\Facades\Http;

/**
 * Higgsfield (image + video) behind a configurable base URL. Generations may be asynchronous:
 * POST /generations returns {id,status}; we poll GET /generations/{id} until completed.
 */
class HiggsfieldDriver extends AbstractDriver implements AIVideoProvider
{
    public function editImage(ImageRequest $request): ImageResult
    {
        $payload = ['model' => $this->provider->model('image'), 'prompt' => $request->prompt, 'aspect_ratio' => $request->aspect];
        if ($request->image !== null) {
            $payload['input_image'] = 'data:'.$request->mime.';base64,'.base64_encode($request->image);
        }
        $json = $this->send(fn ($http) => $http->withToken((string) $this->provider->key())->post($this->provider->baseUrl().'/generations', $payload))->json() ?? [];

        $result = $this->toResult($json);
        $polls = (int) data_get($this->provider->options, 'max_polls', 30);
        $interval = (int) data_get($this->provider->options, 'poll_interval_ms', 2000);
        while ($result->isPending() && $polls-- > 0) {
            if ($interval > 0) {
                usleep($interval * 1000);
            }
            $result = $this->getStatus((string) $result->externalId);
        }
        if ($result->isPending()) {
            throw new ProviderTimeoutException('higgsfield: generation still pending');
        }

        return $result;
    }

    public function generateImage(ImageRequest $request): ImageResult
    {
        return $this->editImage(new ImageRequest($request->prompt, null, aspect: $request->aspect));
    }

    public function getStatus(string $externalId): ImageResult
    {
        $json = $this->send(fn ($http) => $http->withToken((string) $this->provider->key())->get($this->provider->baseUrl().'/generations/'.$externalId))->json() ?? [];

        return $this->toResult($json);
    }

    public function animate(string $image, string $prompt): ImageResult
    {
        $this->unsupported('video (planned)');
    }

    protected function toResult(array $json): ImageResult
    {
        $status = strtolower((string) ($json['status'] ?? 'completed'));
        if (in_array($status, ['failed', 'error', 'cancelled'], true)) {
            throw new ProviderException('higgsfield: '.($json['error'] ?? $status));
        }
        if (in_array($status, ['queued', 'pending', 'processing', 'in_progress', 'running'], true)) {
            return new ImageResult(null, status: 'pending', externalId: (string) ($json['id'] ?? ''));
        }
        if ($b64 = data_get($json, 'images.0.b64') ?? data_get($json, 'output.0.b64_json')) {
            return $this->decodeImage($b64);
        }
        if ($url = data_get($json, 'images.0.url') ?? data_get($json, 'output.0.url')) {
            $download = Http::timeout(30)->get($url);
            if ($download->failed()) {
                throw new ProviderException('higgsfield: could not download result');
            }

            return new ImageResult($download->body(), $download->header('Content-Type') ?: 'image/png', model: $this->provider->model('image'));
        }

        throw new ProviderException('higgsfield: no image in response');
    }

    protected function complete(string $prompt, ?string $image = null, string $mime = 'image/jpeg'): string
    {
        $this->unsupported('text');
    }
}
