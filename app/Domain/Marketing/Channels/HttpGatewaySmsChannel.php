<?php

namespace App\Domain\Marketing\Channels;

use App\Domain\Messaging\Contracts\MessageChannel;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\DTOs\SendResult;
use Illuminate\Support\Facades\Http;

/**
 * Generic HTTP SMS gateway for local operators (Ooredoo, Vodafone Qatar, ...). Configured with:
 *   url, method (get|post), format (form|json|query), params {"user":"x","pass":"y","to":"{to}","text":"{body}","from":"{from}"},
 *   headers {}, from, success_contains (optional string the body must contain), id_path (JSON path of the message id).
 */
class HttpGatewaySmsChannel implements MessageChannel
{
    public function __construct(protected array $config) {}

    public function channel(): string
    {
        return 'sms';
    }

    public function send(OutboundMessage $message): SendResult
    {
        $replace = ['{to}' => $message->to, '{to_digits}' => ltrim($message->to, '+'), '{body}' => SmsText::from($message), '{from}' => (string) ($this->config['from'] ?? '')];
        $params = array_map(fn ($v) => is_string($v) ? strtr($v, $replace) : $v, $this->config['params'] ?? []);
        $method = strtolower($this->config['method'] ?? 'post');
        $format = $this->config['format'] ?? 'form';

        try {
            $request = Http::withHeaders($this->config['headers'] ?? [])->timeout(15);
            $response = match (true) {
                $method === 'get' || $format === 'query' => $request->get($this->config['url'], $params),
                $format === 'json' => $request->asJson()->post($this->config['url'], $params),
                default => $request->asForm()->post($this->config['url'], $params),
            };
        } catch (\Throwable $e) {
            return new SendResult(false, null, $e->getMessage());
        }

        $needle = $this->config['success_contains'] ?? null;
        $ok = $response->successful() && (! $needle || str_contains($response->body(), $needle));

        return $ok
            ? new SendResult(true, isset($this->config['id_path']) ? (string) $response->json($this->config['id_path']) : null)
            : new SendResult(false, null, 'HTTP '.$response->status().' '.mb_substr($response->body(), 0, 120));
    }
}
