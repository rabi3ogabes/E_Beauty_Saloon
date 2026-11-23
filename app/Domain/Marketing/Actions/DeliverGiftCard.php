<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\GiftCardOrder;
use App\Domain\Marketing\Support\MarketingUrl;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Payments\Models\GiftCard;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Sends a paid gift card to its recipient: WhatsApp (SMS fallback) to the phone and/or email, each carrying the
 * signed card page /gift-cards/{code}. Transactional (the purchaser asked for it), so marketing consent is not required.
 */
class DeliverGiftCard
{
    public function __construct(protected MessagingManager $messaging, protected TenantContext $context) {}

    public function handle(GiftCardOrder $order): GiftCardOrder
    {
        return $this->context->run(Tenant::find($order->tenant_id), function () use ($order) {
            $card = GiftCard::find($order->gift_card_id);
            if (! $card || $card->status !== 'active' || $order->delivered_at) {
                return $order;
            }
            $url = MarketingUrl::signedRoute('marketing.gift-cards.show', ['code' => $card->code]);
            $vars = ['name' => $card->recipient_name, 'from' => $order->purchaser_name, 'amount' => money($card->initial_amount, $card->currency), 'salon' => tenant()->name, 'url' => $url];
            $body = __('marketing.gift_cards.delivery_message', $vars)."\n\n".($card->message ? '“'.$card->message.'”'."\n\n" : '').$url;
            $used = [];

            if ($card->recipient_phone) {
                [$channel] = $this->messaging->sendWithFallback(['whatsapp', 'sms'], fn ($ch) => new OutboundMessage($card->recipient_phone, $body,
                    buttons: [['type' => 'url', 'text' => __('marketing.gift_cards.open_card'), 'url' => $url]], meta: ['gift_card_id' => $card->id]));
                $channel && $used[] = $channel;
            }
            if ($card->recipient_email) {
                $result = $this->messaging->send('email', new OutboundMessage($card->recipient_email, nl2br(e($body)), subject: __('marketing.gift_cards.delivery_subject', $vars),
                    buttons: [['type' => 'url', 'text' => __('marketing.gift_cards.open_card'), 'url' => $url]], meta: ['gift_card_id' => $card->id]));
                $result->ok && $used[] = 'email';
            }

            if ($used) {
                $card->update(['sent_at' => now()]);
                $order->update(['delivered_at' => now(), 'delivery' => $used]);
            }

            return $order;
        });
    }
}
