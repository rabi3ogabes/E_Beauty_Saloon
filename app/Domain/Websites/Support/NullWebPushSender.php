<?php

namespace App\Domain\Websites\Support;

use App\Domain\Websites\Contracts\WebPushSender;
use App\Domain\Websites\Models\PushSubscription;
use Illuminate\Support\Facades\Log;

/** Stub sender: logs the push. Replace with a VAPID sender once keys are configured. */
class NullWebPushSender implements WebPushSender
{
    public static array $sent = [];

    public function send(PushSubscription $subscription, array $payload): bool
    {
        static::$sent[] = ['subscription' => $subscription->id, 'payload' => $payload];
        Log::debug('web-push (stub)', ['subscription' => $subscription->id, 'title' => $payload['title'] ?? null]);

        return false;
    }
}
