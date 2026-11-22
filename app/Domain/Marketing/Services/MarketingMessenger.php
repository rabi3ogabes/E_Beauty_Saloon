<?php

namespace App\Domain\Marketing\Services;

use App\Domain\CRM\Models\Client;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\DTOs\SendResult;
use App\Domain\Messaging\MessagingManager;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consent-aware marketing sends. Channels without consent (or without a contact address) are removed from
 * the fallback chain automatically, so "WhatsApp → SMS" silently becomes "SMS" for a client who only opted in to SMS.
 */
class MarketingMessenger
{
    protected static ?Closure $pushTokenResolver = null;

    public function __construct(protected MessagingManager $messaging) {}

    /** Modules owning device registration (mobile API) can provide push tokens. */
    public static function resolvePushTokenUsing(?Closure $resolver): void
    {
        static::$pushTokenResolver = $resolver;
    }

    public function contactFor(Client $client, string $channel): ?string
    {
        return match ($channel) {
            'whatsapp', 'sms' => $client->phone ?: null,
            'email' => $client->email ?: null,
            'push' => $this->pushToken($client),
            default => null,
        };
    }

    /** Channels from $preferred (in order) the client has consented to and can be reached on. */
    public function reachableChannels(Client $client, array $preferred): array
    {
        return array_values(array_filter($preferred, fn ($ch) => $client->hasConsent($ch) && $this->contactFor($client, $ch)));
    }

    /**
     * @param  Closure(string $channel, string $to): ?OutboundMessage  $messageFor
     * @return array{0: ?string, 1: SendResult}
     */
    public function send(Client $client, array $channels, Closure $messageFor, bool $requireConsent = true): array
    {
        $chain = $requireConsent ? $this->reachableChannels($client, $channels) : array_values(array_filter($channels, fn ($ch) => $this->contactFor($client, $ch)));
        if (! $chain) {
            return [null, new SendResult(false, null, 'no_consent')];
        }

        return $this->messaging->sendWithFallback($chain, fn (string $channel) => $messageFor($channel, (string) $this->contactFor($client, $channel)));
    }

    protected function pushToken(Client $client): ?string
    {
        if (static::$pushTokenResolver) {
            return (static::$pushTokenResolver)($client);
        }
        if (Schema::hasTable('client_devices') && Schema::hasColumn('client_devices', 'token')) {
            return DB::table('client_devices')->where('client_id', $client->id)->latest('id')->value('token');
        }

        return null;
    }
}
