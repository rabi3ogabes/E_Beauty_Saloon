<?php

namespace App\Domain\AIStudio\Providers;

use App\Domain\AIStudio\Contracts\AIImageProvider;
use App\Domain\AIStudio\Contracts\AITextProvider;
use App\Domain\AIStudio\DTOs\ImageRequest;
use App\Domain\AIStudio\DTOs\ImageResult;
use App\Domain\AIStudio\Exceptions\ProviderException;
use App\Domain\AIStudio\Exceptions\ProviderTimeoutException;
use App\Domain\AIStudio\Models\AIProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared plumbing for HTTP drivers: timeouts mapped to ProviderTimeoutException, error mapping,
 * and the text-model prompts (analysis / recommendations / captions) built on a single complete() call.
 */
abstract class AbstractDriver implements AIImageProvider, AITextProvider
{
    public function __construct(protected AIProvider $provider) {}

    /** Single text/vision completion. Drivers without a text model throw. */
    abstract protected function complete(string $prompt, ?string $image = null, string $mime = 'image/jpeg'): string;

    /**
     * Hook for other modules (Insights, Assistant): one plain text completion through this provider.
     * Callers route it with AIRouter (capability "text") and charge credits themselves.
     */
    public function completeText(string $prompt): string
    {
        return $this->complete($prompt);
    }

    protected function http(): PendingRequest
    {
        return Http::timeout(max(5, (int) ($this->provider->timeout_seconds ?: config('ai.timeout'))))
            ->connectTimeout(10)
            ->acceptJson();
    }

    /** Execute a request closure and normalise failures. */
    protected function send(callable $call): Response
    {
        try {
            $response = $call($this->http());
        } catch (ConnectionException $e) {
            throw new ProviderTimeoutException($this->provider->driver.': '.$e->getMessage(), 0, $e);
        }
        if ($response->status() === 408 || $response->status() === 504) {
            throw new ProviderTimeoutException($this->provider->driver.': upstream timeout ('.$response->status().')');
        }
        if ($response->failed()) {
            throw new ProviderException($this->provider->driver.': HTTP '.$response->status().' '.mb_substr((string) $response->body(), 0, 200));
        }

        return $response;
    }

    protected function unsupported(string $what): never
    {
        throw new ProviderException($this->provider->driver.' does not support '.$what);
    }

    public function editImage(ImageRequest $request): ImageResult
    {
        $this->unsupported('image editing');
    }

    public function generateImage(ImageRequest $request): ImageResult
    {
        $this->unsupported('image generation');
    }

    public function inpaint(ImageRequest $request): ImageResult
    {
        return $this->editImage($request);
    }

    public function getStatus(string $externalId): ImageResult
    {
        $this->unsupported('async status');
    }

    public function analyzeFace(string $image, string $mime, string $locale): array
    {
        $language = $locale === 'ar' ? 'Arabic' : 'English';
        $text = $this->complete(
            "You are a senior beauty consultant. Look at this photo and describe, for styling purposes only, the person's face shape, skin tone, undertone and hair texture. "
            ."Do not guess identity, age, ethnicity or health. Reply ONLY with a JSON object with keys face_shape, skin_tone, undertone, hair_texture, notes. Values in {$language}.",
            $image, $mime,
        );
        $json = self::extractJson($text);

        return [
            'face_shape' => $json['face_shape'] ?? null,
            'skin_tone' => $json['skin_tone'] ?? null,
            'undertone' => $json['undertone'] ?? null,
            'hair_texture' => $json['hair_texture'] ?? null,
            'notes' => $json['notes'] ?? null,
        ];
    }

    public function recommend(array $analysis, array $styles, string $locale): array
    {
        $language = $locale === 'ar' ? 'Arabic' : 'English';
        $text = $this->complete(
            'Client analysis: '.json_encode($analysis, JSON_UNESCAPED_UNICODE)."\nAvailable salon looks: ".json_encode($styles, JSON_UNESCAPED_UNICODE)
            ."\nChoose the 3 most flattering looks from the list for this client. Reply ONLY with a JSON array of objects {\"style_id\": number, \"reason\": string}. "
            ."Reasons are one warm, specific sentence in {$language}.",
        );
        $json = self::extractJson($text);
        $valid = array_column($styles, 'id');

        return collect(is_array($json) ? (array_is_list($json) ? $json : ($json['looks'] ?? [])) : [])
            ->filter(fn ($r) => is_array($r) && in_array((int) ($r['style_id'] ?? 0), $valid, true))
            ->map(fn ($r) => ['style_id' => (int) $r['style_id'], 'reason' => (string) ($r['reason'] ?? '')])
            ->unique('style_id')->take(3)->values()->all();
    }

    public function writePrompt(array $context): string
    {
        return trim($this->complete(
            'Write one precise instruction for an image-editing model to apply this salon look to the provided photo, keeping the person\'s identity, '
            .'face, skin and background unchanged and photorealistic. Context: '.json_encode($context, JSON_UNESCAPED_UNICODE).'. Reply with the instruction only.'
        ));
    }

    public function writeCaption(string $brief, string $tone, string $locale): string
    {
        $language = $locale === 'ar' ? 'Arabic (Gulf-friendly, elegant)' : 'English';

        return trim($this->complete("Write a short {$tone} marketing caption for a luxury beauty salon in {$language}. Brief: {$brief}. Reply with the caption only, no quotes."));
    }

    /** Pull the first JSON object/array out of a model reply (handles ```json fences and prose). */
    public static function extractJson(string $text): array
    {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $m)) {
            $text = trim($m[1]);
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        foreach (['[' => ']', '{' => '}'] as $open => $close) {
            $start = strpos($text, $open);
            $end = strrpos($text, $close);
            if ($start !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        throw new ProviderException($this::class.': model did not return JSON');
    }

    protected function decodeImage(?string $b64, string $mime = 'image/png'): ImageResult
    {
        $bin = $b64 ? base64_decode($b64, true) : false;
        if (! $bin) {
            throw new ProviderException($this->provider->driver.': no image in response');
        }

        return new ImageResult($bin, $mime, model: $this->provider->model('image'));
    }
}
