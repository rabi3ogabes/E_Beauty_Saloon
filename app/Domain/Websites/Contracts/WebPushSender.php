<?php

namespace App\Domain\Websites\Contracts;

use App\Domain\Websites\Models\PushSubscription;

/**
 * Sends a web push notification to one subscription. The default binding stores nothing and sends
 * nothing (NullWebPushSender); bind a VAPID implementation (e.g. minishlink/web-push) in a provider.
 */
interface WebPushSender
{
    /** @param array{title:string, body?:string, url?:string, icon?:string} $payload */
    public function send(PushSubscription $subscription, array $payload): bool;
}
