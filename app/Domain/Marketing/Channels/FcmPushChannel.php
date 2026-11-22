<?php

namespace App\Domain\Marketing\Channels;

use App\Domain\Messaging\Contracts\MessageChannel;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\DTOs\SendResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Firebase Cloud Messaging HTTP v1 (web push + Android + iOS) with rich image.
 * Credentials: project_id + service_account (JSON key). OAuth2 access tokens are minted with a signed JWT and cached.
 */
class FcmPushChannel implements MessageChannel
{
    public function __construct(protected string $projectId, protected array $serviceAccount) {}

    public function channel(): string
    {
        return 'push';
    }

    public function send(OutboundMessage $message): SendResult
    {
        $notification = array_filter(['title' => $message->subject ?? tenant()?->name, 'body' => strip_tags($message->body), 'image' => $message->mediaUrl]);
        $link = collect($message->buttons)->firstWhere('type', 'url')['url'] ?? null;

        try {
            $response = Http::withToken($this->accessToken())->acceptJson()->timeout(15)
                ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send", ['message' => array_filter([
                    'token' => $message->to,
                    'notification' => $notification,
                    'data' => array_map('strval', array_filter(['link' => $link, 'campaign_id' => $message->meta['campaign_id'] ?? null])),
                    'webpush' => $link ? ['fcm_options' => ['link' => $link]] : null,
                    'apns' => $message->mediaUrl ? ['payload' => ['aps' => ['mutable-content' => 1]], 'fcm_options' => ['image' => $message->mediaUrl]] : null,
                ])]);
        } catch (\Throwable $e) {
            return new SendResult(false, null, $e->getMessage());
        }

        return $response->successful()
            ? new SendResult(true, $response->json('name'))
            : new SendResult(false, null, (string) ($response->json('error.message') ?? 'HTTP '.$response->status()));
    }

    protected function accessToken(): string
    {
        $email = $this->serviceAccount['client_email'] ?? '';

        return Cache::remember('fcm-token:'.md5($email.$this->projectId), 3300, function () use ($email) {
            $now = time();
            $segments = [
                self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                self::b64(json_encode([
                    'iss' => $email, 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
                ])),
            ];
            openssl_sign(implode('.', $segments), $signature, (string) ($this->serviceAccount['private_key'] ?? ''), OPENSSL_ALGO_SHA256);
            $segments[] = self::b64((string) $signature);

            return (string) Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => implode('.', $segments),
            ])->throw()->json('access_token');
        });
    }

    protected static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
