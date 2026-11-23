<?php

namespace App\Domain\Messaging;

use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\Channels\LogChannel;
use App\Domain\Messaging\Channels\MailChannel;
use App\Domain\Messaging\Contracts\MessageChannel;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\DTOs\SendResult;
use Closure;

/**
 * Resolves the MessageChannel for (tenant, channel). Modules extend it:
 *   app(MessagingManager::class)->extend('whatsapp', fn ($tenant) => new MetaWhatsAppChannel(...));
 * Resolvers may return null to fall back to the default (log for whatsapp/sms/push, mail for email).
 */
class MessagingManager
{
    /** @var array<string, Closure> */
    protected array $resolvers = [];

    protected static bool $faked = false;

    public static function fake(bool $fake = true): void
    {
        static::$faked = $fake;
        FakeChannel::reset();
    }

    public function extend(string $channel, Closure $resolver): void
    {
        $this->resolvers[$channel] = $resolver;
    }

    public function channel(string $channel): MessageChannel
    {
        if (static::$faked) {
            return new FakeChannel($channel);
        }
        if (isset($this->resolvers[$channel]) && ($driver = ($this->resolvers[$channel])(tenant()))) {
            return $driver;
        }

        return $channel === 'email' ? new MailChannel : new LogChannel($channel);
    }

    public function send(string $channel, OutboundMessage $message): SendResult
    {
        return $this->channel($channel)->send($message);
    }

    /**
     * Try channels in order (e.g. ['whatsapp', 'sms']) until one succeeds.
     *
     * @return array{0: ?string, 1: SendResult} [channel used, result]
     */
    public function sendWithFallback(array $channels, Closure $messageFor): array
    {
        $last = new SendResult(false, null, 'no channel');
        foreach ($channels as $channel) {
            $message = $messageFor($channel);
            if (! $message) {
                continue;
            }
            $last = $this->send($channel, $message);
            if ($last->ok) {
                return [$channel, $last];
            }
        }

        return [null, $last];
    }
}
