<?php

namespace App\Domain\Shop\Listeners;

use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Events\OrderPlaced;
use App\Domain\Shop\Events\OrderStatusChanged;
use App\Domain\Shop\Models\Order;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;

/**
 * WhatsApp (fallback SMS) + email update to the client on order placement and every status change,
 * in the client's language.
 */
class SendOrderUpdate
{
    public function __construct(protected MessagingManager $messaging) {}

    public function handle(OrderPlaced|OrderStatusChanged $event): void
    {
        if ($event instanceof OrderStatusChanged && $event->from === null) {
            return; // placement is announced by OrderPlaced
        }
        $order = $event->order;
        $key = $event instanceof OrderPlaced ? 'placed' : $event->to->value;

        $ctx = app(TenantContext::class);
        $tenant = $ctx->id() === $order->tenant_id ? $ctx->tenant() : Tenant::find($order->tenant_id);
        $ctx->run($tenant, fn () => $this->send($order, $key));
    }

    protected function send(Order $order, string $key): void
    {
        $locale = in_array($order->locale, ['en', 'ar'], true) ? $order->locale : 'en';
        $order->loadMissing(['branch' => fn ($q) => $q->withoutGlobalScopes(), 'driver']);
        $tz = $order->branch?->timezone ?: 'Asia/Qatar';
        $vars = [
            'name' => explode(' ', trim($order->customer_name))[0],
            'number' => $order->number,
            'salon' => tenant()?->name,
            'total' => Money::of((int) $order->total, $order->currency)->format($locale),
            'branch' => $order->branch?->getTranslation('name', $locale),
            'time' => $order->ready_estimate_at?->setTimezone($tz)->locale($locale)->translatedFormat('D j M, H:i'),
            'driver' => $order->driver?->name,
            'driver_phone' => $order->driver?->phone,
            'url' => $order->trackingUrl(),
            'reason' => $order->cancel_reason ? __('shop.cancel_reasons.'.$order->cancel_reason, [], $locale) : '',
        ];
        if ($key === 'placed' && $order->cod_otp_required && ! $order->cod_otp_verified_at) {
            $key = 'placed_otp';
        }
        $body = __('shop.notify.'.$key, $vars, $locale);
        $statusLabel = OrderStatus::tryFrom($key)?->label($locale) ?? __('shop.notify.placed_title', [], $locale);

        $this->messaging->sendWithFallback(['whatsapp', 'sms'], fn ($channel) => new OutboundMessage(
            to: $order->customer_phone,
            body: $body,
            template: $channel === 'whatsapp' ? 'order_update' : null,
            variables: [$vars['name'], $order->number, $statusLabel, $vars['url']],
            buttons: [['type' => 'url', 'text' => __('shop.notify.track', [], $locale), 'url' => $vars['url']]],
            locale: $locale,
            meta: ['type' => 'shop_order', 'order_id' => $order->id, 'status' => $key],
        ));

        if ($order->customer_email) {
            $this->messaging->send('email', new OutboundMessage(
                to: $order->customer_email,
                body: nl2br(e($body)),
                subject: __('shop.notify.subject', ['number' => $order->number, 'status' => $statusLabel, 'salon' => $vars['salon']], $locale),
                locale: $locale,
                meta: ['type' => 'shop_order', 'order_id' => $order->id, 'status' => $key],
            ));
        }
    }
}
