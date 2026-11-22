<?php

namespace App\Domain\Marketing\Channels;

use App\Domain\Messaging\Contracts\MessageChannel;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\DTOs\SendResult;
use Illuminate\Support\Facades\Http;

class TwilioSmsChannel implements MessageChannel
{
    public function __construct(protected string $accountSid, protected string $authToken, protected string $from) {}

    public function channel(): string
    {
        return 'sms';
    }

    public function send(OutboundMessage $message): SendResult
    {
        try {
            $response = Http::asForm()->withBasicAuth($this->accountSid, $this->authToken)->timeout(15)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json", [
                    'To' => $message->to, 'From' => $this->from, 'Body' => SmsText::from($message),
                ]);
        } catch (\Throwable $e) {
            return new SendResult(false, null, $e->getMessage());
        }

        return $response->successful()
            ? new SendResult(true, $response->json('sid'))
            : new SendResult(false, null, (string) ($response->json('message') ?? 'HTTP '.$response->status()));
    }
}
