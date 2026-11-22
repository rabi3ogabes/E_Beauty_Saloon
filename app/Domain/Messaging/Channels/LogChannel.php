<?php

namespace App\Domain\Messaging\Channels;

use App\Domain\Messaging\Contracts\MessageChannel;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\DTOs\SendResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Development driver: writes messages to the log. */
class LogChannel implements MessageChannel
{
    public function __construct(protected string $channel) {}

    public function channel(): string
    {
        return $this->channel;
    }

    public function send(OutboundMessage $message): SendResult
    {
        Log::channel(config('logging.default'))->info("[{$this->channel}] → {$message->to}", ['body' => $message->body, 'template' => $message->template, 'meta' => $message->meta]);

        return new SendResult(true, 'log_'.Str::random(10));
    }
}
