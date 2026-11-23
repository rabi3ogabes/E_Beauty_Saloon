<?php

namespace App\Domain\Marketing\Channels;

use App\Domain\Marketing\Services\MessageCredits;
use App\Domain\Messaging\Contracts\MessageChannel;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\DTOs\SendResult;
use Illuminate\Support\Str;

/**
 * Decorates a platform-shared driver: debits the tenant's message credit wallet before sending
 * (hard stop when the balance is insufficient) and refunds the debit when the provider rejects the message.
 */
class CreditMeteredChannel implements MessageChannel
{
    public function __construct(protected MessageChannel $inner, protected int $tenantId, protected int $price) {}

    public function channel(): string
    {
        return $this->inner->channel();
    }

    public function send(OutboundMessage $message): SendResult
    {
        $credits = app(MessageCredits::class);
        $key = 'msg:'.$this->tenantId.':'.Str::ulid();

        if ($this->price > 0 && ! $credits->debit($this->tenantId, $this->price, $this->channel(), $key, $message->meta['reference'] ?? null)) {
            return new SendResult(false, null, MessageCredits::INSUFFICIENT);
        }

        $result = $this->inner->send($message);
        if (! $result->ok && $this->price > 0) {
            $credits->refund($this->tenantId, $this->price, $this->channel(), $key);

            return $result;
        }

        return new SendResult($result->ok, $result->providerMessageId, $result->error, $this->price);
    }
}
