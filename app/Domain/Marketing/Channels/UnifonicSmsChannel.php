<?php

namespace App\Domain\Marketing\Channels;

use App\Domain\Messaging\Contracts\MessageChannel;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\DTOs\SendResult;
use Illuminate\Support\Facades\Http;

/** Unifonic (GCC) REST SMS. */
class UnifonicSmsChannel implements MessageChannel
{
    public function __construct(protected string $appSid, protected string $senderId, protected string $endpoint = 'https://el.cloud.unifonic.com/rest/SMS/messages') {}

    public function channel(): string
    {
        return 'sms';
    }

    public function send(OutboundMessage $message): SendResult
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->post($this->endpoint, [
                'AppSid' => $this->appSid, 'SenderID' => $this->senderId, 'Recipient' => ltrim($message->to, '+'), 'Body' => SmsText::from($message),
            ]);
        } catch (\Throwable $e) {
            return new SendResult(false, null, $e->getMessage());
        }

        $ok = $response->successful() && filter_var($response->json('success', true), FILTER_VALIDATE_BOOLEAN);

        return $ok
            ? new SendResult(true, (string) ($response->json('data.MessageID') ?? $response->json('MessageID')))
            : new SendResult(false, null, (string) ($response->json('message') ?? 'HTTP '.$response->status()));
    }
}
