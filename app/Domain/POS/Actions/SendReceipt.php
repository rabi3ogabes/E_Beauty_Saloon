<?php

namespace App\Domain\POS\Actions;

use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\DTOs\SendResult;
use App\Domain\Messaging\MessagingManager;
use App\Domain\POS\Models\Sale;
use App\Support\Exceptions\DomainException;

/** Sends the signed receipt link by email or WhatsApp, or builds a wa.me share link. */
class SendReceipt
{
    public function __construct(protected MessagingManager $messaging) {}

    public function handle(Sale $sale, string $channel, string $to): SendResult
    {
        if (! in_array($channel, ['email', 'whatsapp', 'sms'], true) || trim($to) === '') {
            throw new DomainException(__('pos.errors.receipt_destination'));
        }
        $locale = $sale->client?->locale ?? app()->getLocale();
        $body = __('pos.receipt.message', ['salon' => tenant()?->name, 'number' => $sale->number, 'total' => money((int) $sale->total, $sale->currency), 'url' => $sale->receiptUrl()], $locale);

        $result = $this->messaging->send($channel, new OutboundMessage(
            to: trim($to),
            body: $body,
            subject: $channel === 'email' ? __('pos.receipt.subject', ['salon' => tenant()?->name, 'number' => $sale->number], $locale) : null,
            buttons: [['type' => 'url', 'text' => __('pos.receipt.view', [], $locale), 'url' => $sale->receiptUrl()]],
            locale: $locale,
            meta: ['sale_id' => $sale->id],
        ));
        activity()->performedOn($sale)->withProperties(['channel' => $channel, 'ok' => $result->ok])->log('pos.receipt.sent');

        return $result;
    }

    public function whatsappLink(Sale $sale, ?string $phone = null): string
    {
        $phone = preg_replace('/\D/', '', (string) ($phone ?? $sale->client?->phone));
        $text = __('pos.receipt.message', ['salon' => tenant()?->name, 'number' => $sale->number, 'total' => money((int) $sale->total, $sale->currency), 'url' => $sale->receiptUrl()]);

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($text);
    }
}
