<?php

namespace App\Domain\Messaging\Channels;

use App\Domain\Messaging\Contracts\MessageChannel;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\DTOs\SendResult;

/** Test driver. Configure failures with FakeChannel::$failFor['whatsapp'] = true. */
class FakeChannel implements MessageChannel
{
    /** @var list<array{channel:string,message:OutboundMessage}> */
    public static array $sent = [];

    public static array $failFor = [];

    public function __construct(protected string $channel) {}

    public function channel(): string
    {
        return $this->channel;
    }

    public function send(OutboundMessage $message): SendResult
    {
        if (static::$failFor[$this->channel] ?? false) {
            return new SendResult(false, null, 'fake failure');
        }
        static::$sent[] = ['channel' => $this->channel, 'message' => $message];

        return new SendResult(true, 'fake_'.count(static::$sent));
    }

    public static function reset(): void
    {
        static::$sent = [];
        static::$failFor = [];
    }

    public static function sentTo(string $channel, ?string $to = null): array
    {
        return array_values(array_filter(static::$sent, fn ($s) => $s['channel'] === $channel && ($to === null || $s['message']->to === $to)));
    }
}
